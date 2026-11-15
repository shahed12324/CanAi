<?php

declare(strict_types=1);

namespace App\Inbox\Agent\Orders;

use App\Inbox\Agent\AgentRepo;
use App\Inbox\Agent\Knowledge;
use App\Inbox\InboxRepo;
use App\Orders\OrderRepo;
use App\Orders\OrderText;

/**
 * Takes an order in chat. The model only extracts details; every question, price, summary and the final "yes" is produced or checked by code.
 *  - nothing is created until the customer answers a plain yes to a summary that is exactly the current draft (fingerprint);
 *  - the order is created by order_create(): live prices, stock hold, risk score, and the AI cannot give any discount (0%);
 *  - afterwards the chat goes to a person (reason order_placed) who phones to confirm.
 * run() returns: sent | order_cancelled | order:<number> | handoff:<reason>
 */
final class OrderFlow
{
    public const MAX_TURNS = 12;

    public function __construct(
        private readonly InboxRepo $inbox,
        private readonly AgentRepo $agent,
        private readonly OrderDraftRepo $drafts,
        private readonly OrderRepo $orders,
        private readonly ModelCall $model,
    ) {}

    public function enabled(): bool
    {
        return $this->orders->settings()['chat_orders'];
    }

    public function active(string $conversationId): bool
    {
        return $this->drafts->get($conversationId) !== null;
    }

    public function discard(string $conversationId): void
    {
        $this->drafts->delete($conversationId);
    }

    /** @param list<array{from: string, text: string}> $history */
    public function run(string $tenantId, string $conversationId, string $text, array $history): string
    {
        $settings = $this->orders->settings();
        $draft = $this->drafts->get($conversationId) ?? new OrderDraft;
        if (++$draft->turns > self::MAX_TURNS) {
            $this->drafts->delete($conversationId);

            return 'handoff:order_turns';
        }
        $delivery = fn (OrderDraft $d) => $d->zone === 'inside' ? $settings['delivery_inside_minor'] : $settings['delivery_outside_minor'];

        // Products the customer is talking about now + the ones already in the draft.
        $found = $this->agent->search(Knowledge::keywords($text));
        $products = $found['matched'] ? $found['products'] : [];
        $have = array_column($products, 'id');
        $held = $draft->items === [] ? [] : $this->agent->byVariants(array_column($draft->items, 'variant_id'));
        foreach ($held as $p) {
            if (! in_array($p['id'], $have, true)) {
                $products[] = $p;
            }
        }
        $index = OrderCatalog::index($products);

        // An item that is no longer on sale (unpublished / archived since it was added) is dropped, and the customer is told.
        $gone = array_values(array_filter($draft->items, fn ($it) => ! isset($index[$it['variant_id']])));
        if ($gone !== []) {
            $draft->items = array_values(array_filter($draft->items, fn ($it) => isset($index[$it['variant_id']])));
            $draft->state = 'collecting';
            $draft->hash = null;

            return $this->reply($tenantId, $conversationId, $draft, 'Sorry, one of the products you chose is not for sale right now. '.$this->summaryOrAsk($draft, $index, $delivery($draft), null));
        }

        // A plain yes to the exact summary we showed: create the order. No model call at all.
        if ($draft->state === 'awaiting_confirm' && $draft->complete() && $draft->hash !== null && Affirm::isYes($text)) {
            if ($draft->fingerprint($index, $delivery($draft)) !== $draft->hash) {
                $draft->state = 'collecting';                      // price/stock moved since the summary: show it again
                $draft->hash = null;

                return $this->reply($tenantId, $conversationId, $draft, $this->summaryOrAsk($draft, $index, $delivery($draft), $draft));
            }

            return $this->place($tenantId, $conversationId, $draft, $index);
        }

        if ($index === [] && $draft->items === []) {
            $this->drafts->save($tenantId, $conversationId, $draft);

            return $this->reply($tenantId, $conversationId, $draft, 'Which product would you like to take? Write the product name.');
        }

        $messages = OrderPrompt::build($index, $draft, $history);
        $r = $this->model->run($tenantId, $messages, fn (string $t) => OrderPrompt::parse($t) !== null);
        if (! $r['ok']) {
            $this->drafts->delete($conversationId);

            return 'handoff:'.($r['fail'] === 'unusable' ? 'unsure' : ($r['fail'] ?? 'unsure'));
        }
        $x = OrderPrompt::parse($r['text']) ?? throw new \LogicException('accepted output must parse');
        if ($x['cancel']) {
            $this->drafts->delete($conversationId);
            $this->say($conversationId, 'Okay, the order was not placed. Write if you want to know anything else.');

            return 'order_cancelled';
        }

        $beforeHash = $draft->hash;
        $res = $draft->apply($x, $index);
        $reply = null;
        if ($res['out_of_stock'] !== []) {
            $reply = 'Sorry, '.implode(', ', array_slice($res['out_of_stock'], 0, 3)).' is out of stock now.';
        } elseif ($x['ambiguous'] !== null && $draft->items === []) {
            $reply = $this->askVariant($x['ambiguous'], $index);
        } elseif ($res['bad_phone']) {
            $reply = 'The mobile number does not look right. Give an 11-digit number (for example 01712345678).';
        }
        if ($reply === null) {
            $reply = $this->summaryOrAsk($draft, $index, $delivery($draft), null);
            if ($draft->complete() && $beforeHash !== null && $draft->hash === $beforeHash && ! $res['changed']) {
                $reply = 'To confirm the order "Yes" write, or tell me what you want to change.';
            }
        }

        return $this->reply($tenantId, $conversationId, $draft, $reply);
    }

    /** Summary (and the one-time upsell) when complete, otherwise ONE question for what is missing. */
    private function summaryOrAsk(OrderDraft $d, array $index, int $delivery, ?OrderDraft $unused): string
    {
        $m = $d->missing();
        if ($m === []) {
            $d->state = 'awaiting_confirm';
            $d->hash = $d->fingerprint($index, $delivery);
            $s = $d->summary($index, $delivery);
            if (! $d->upsold) {
                $d->upsold = true;
                $u = $this->upsell($d, $index);
                if ($u !== null) {
                    $s .= "\n\n".$u;
                }
            }

            return $s;
        }
        $d->state = 'collecting';
        $d->hash = null;
        if ($m[0] === 'items') {
            return 'Which product and how many will you take? Write the product name and quantity.';
        }
        $need = array_map(fn ($c) => ['name' => 'Your name', 'phone' => 'Mobile number', 'address' => 'Full address (including area and district)'][$c], $m);

        return 'For the order '.implode(', ', $need).' write and tell me.';
    }

    private function upsell(OrderDraft $d, array $index): ?string
    {
        $first = $index[$d->items[0]['variant_id']] ?? null;
        if ($first === null) {
            return null;
        }
        $own = array_values(array_unique(array_map(fn ($it) => $index[$it['variant_id']]['product'] ?? '', $d->items)));
        $cat = null;
        foreach ($this->agent->byVariants([$d->items[0]['variant_id']]) as $p) {
            $cat = is_array($p['live'] ?? null) ? ($p['live']['category'] ?? null) : null;
        }
        foreach ($this->agent->related(is_string($cat) ? $cat : null, $own, 1) as $p) {
            $l = is_array($p['live'] ?? null) ? $p['live'] : [];
            if (($l['title'] ?? '') !== '') {
                return 'If you wish you can also take "'.preg_replace('/[\r\n]+/', ' ', (string) $l['title']).'" ('.OrderText::money((int) ($l['price_minor'] ?? 0), (string) ($l['currency'] ?? 'BDT')).') too — tell me if you like.';
            }
        }

        return null;
    }

    private function askVariant(string $ref, array $index): string
    {
        $opts = [];
        foreach ($index as $f) {
            if ($f['ref'] === $ref && $f['in_stock']) {
                $opts[] = $f['label'].' — '.OrderText::money($f['unit'], $f['currency']);
            }
        }
        if ($opts === []) {
            return 'Sorry, this product is out of stock now.';
        }

        return "Which one will you take?\n".implode("\n", array_map(fn ($i, $o) => ($i + 1).') '.$o, array_keys(array_slice($opts, 0, 8)), array_slice($opts, 0, 8)));
    }

    private function place(string $tenantId, string $conversationId, OrderDraft $d, array $index): string
    {
        $r = $this->orders->create($tenantId, null, 'agent', $conversationId, (string) $d->name, (string) $d->phone, (string) $d->address, (string) $d->zone, null, $d->items, 0.0);
        switch ($r['result']) {
            case 'ok':
                $this->drafts->delete($conversationId);
                $this->say($conversationId, 'Thank you! Your order #'.$r['number'].' has been received. Total '.OrderText::money((int) $r['total_minor'], $this->currency($d, $index)).' (cash on delivery). Our representative will call to confirm.');
                $this->inbox->setMode($conversationId, null, 'human', 'order_placed');

                return 'order:'.$r['number'];
            case 'bad_phone':
                $d->phone = null;
                $d->state = 'collecting';
                $d->hash = null;

                return $this->reply($tenantId, $conversationId, $d, 'The mobile number is not right. Write an 11-digit number again.');
            case 'bad_address':
                $d->address = null;
                $d->state = 'collecting';
                $d->hash = null;

                return $this->reply($tenantId, $conversationId, $d, 'Write the address in more detail (area, road/house, district).');
            case 'bad_name':
                $d->name = null;
                $d->state = 'collecting';
                $d->hash = null;

                return $this->reply($tenantId, $conversationId, $d, 'Write your name.');
            case 'insufficient_stock':
            case 'unavailable':
                $this->drafts->delete($conversationId);

                return 'handoff:order_stock';
            default:
                $this->drafts->delete($conversationId);

                return 'handoff:order_failed';
        }
    }

    private function currency(OrderDraft $d, array $index): string
    {
        foreach ($d->items as $it) {
            if (isset($index[$it['variant_id']])) {
                return $index[$it['variant_id']]['currency'];
            }
        }

        return 'BDT';
    }

    /** Save the draft, send the text. A failed send hands the chat to a person instead of leaving the customer unanswered. */
    private function reply(string $tenantId, string $conversationId, OrderDraft $d, string $text): string
    {
        $this->drafts->save($tenantId, $conversationId, $d);

        return $this->say($conversationId, $text) ? 'sent' : 'handoff:unsure';
    }

    private function say(string $conversationId, string $text): bool
    {
        $r = $this->inbox->send($conversationId, null, 'agent', $text);

        return in_array($r['result'], ['queued', 'sent'], true);
    }
}
