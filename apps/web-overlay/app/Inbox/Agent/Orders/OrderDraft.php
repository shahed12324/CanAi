<?php

declare(strict_types=1);

namespace App\Inbox\Agent\Orders;

use App\Orders\OrderText;

/** The order being collected in one chat. Pure logic: merges what the model extracted, decides what is missing, writes the summary the customer must confirm. */
final class OrderDraft
{
    public const MAX_QTY = 10;

    /** @param list<array{variant_id: string, qty: int}> $items */
    public function __construct(
        public array $items = [],
        public ?string $name = null,
        public ?string $phone = null,
        public ?string $address = null,
        public ?string $zone = null,
        public string $state = 'collecting',
        public ?string $hash = null,
        public bool $upsold = false,
        public int $turns = 0,
    ) {}

    /** BD mobile number in common spellings -> +8801XXXXXXXXX or null (same rule as the database). */
    public static function phone(?string $p): ?string
    {
        $d = preg_replace('/\D/', '', strtr((string) $p, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']));
        if (preg_match('/\A8801[3-9]\d{8}\z/', $d) === 1) {
            return '+'.$d;
        }
        if (preg_match('/\A01[3-9]\d{8}\z/', $d) === 1) {
            return '+88'.$d;
        }

        return null;
    }

    public static function zoneOf(string $address): string
    {
        return preg_match('/ঢাকা|dhaka/iu', $address) === 1 ? 'inside' : 'outside';
    }

    /**
     * Merge a model extraction. Only variants that were actually offered, in stock, with sane quantities are accepted.
     *
     * @param  array{items: ?list<array{variant_id: string, qty: int}>, name: ?string, phone: ?string, address: ?string}  $x
     * @param  array<string, array<string, mixed>>  $index
     * @return array{changed: bool, out_of_stock: list<string>, bad_phone: bool}
     */
    public function apply(array $x, array $index): array
    {
        $before = $this->fingerprintData();
        $oos = [];
        $badPhone = false;
        if ($x['items'] !== null && $x['items'] !== []) {
            $merged = [];
            foreach ($x['items'] as $it) {
                $f = $index[$it['variant_id']] ?? null;
                if ($f === null) {
                    continue;                                   // not something we showed: ignored, never invented
                }
                if (! $f['in_stock']) {
                    $oos[] = $f['label'];

                    continue;
                }
                $merged[$it['variant_id']] = min(self::MAX_QTY, max(1, ($merged[$it['variant_id']] ?? 0) + $it['qty']));
            }
            if ($merged !== []) {
                $this->items = [];
                foreach ($merged as $vid => $q) {
                    $this->items[] = ['variant_id' => $vid, 'qty' => $q];
                }
            }
        }
        if ($x['name'] !== null && trim($x['name']) !== '') {
            $this->name = mb_substr(trim($x['name']), 0, 120);
        }
        if ($x['phone'] !== null && trim($x['phone']) !== '') {
            $p = self::phone($x['phone']);
            $p === null ? $badPhone = true : $this->phone = $p;
        }
        if ($x['address'] !== null && mb_strlen(trim($x['address'])) >= 10) {
            $this->address = mb_substr(trim($x['address']), 0, 500);
            $this->zone = self::zoneOf($this->address);
        }

        return ['changed' => $before !== $this->fingerprintData(), 'out_of_stock' => $oos, 'bad_phone' => $badPhone];
    }

    /** @return list<string> missing field codes, in the order we ask */
    public function missing(): array
    {
        $m = [];
        if ($this->items === []) {
            $m[] = 'items';
        }
        if ($this->name === null) {
            $m[] = 'name';
        }
        if ($this->phone === null) {
            $m[] = 'phone';
        }
        if ($this->address === null) {
            $m[] = 'address';
        }

        return $m;
    }

    public function complete(): bool
    {
        return $this->missing() === [];
    }

    /** What the customer saw, as a short fingerprint. A "yes" only counts for the same fingerprint. */
    public function fingerprint(array $index, int $deliveryMinor): string
    {
        return substr(hash('sha256', json_encode([$this->fingerprintData(), $this->total($index, $deliveryMinor)])), 0, 16);
    }

    /** @param array<string, array<string, mixed>> $index */
    public function subtotal(array $index): int
    {
        $s = 0;
        foreach ($this->items as $it) {
            $s += ($index[$it['variant_id']]['unit'] ?? 0) * $it['qty'];
        }

        return $s;
    }

    public function total(array $index, int $deliveryMinor): int
    {
        return $this->subtotal($index) + $deliveryMinor;
    }

    /** @param array<string, array<string, mixed>> $index */
    public function summary(array $index, int $deliveryMinor): string
    {
        $cur = 'BDT';
        $lines = [];
        foreach ($this->items as $n => $it) {
            $f = $index[$it['variant_id']];
            $cur = $f['currency'];
            $lines[] = ($n + 1).') '.$f['label'].' × '.$it['qty'].' = '.OrderText::money($f['unit'] * $it['qty'], $cur);
        }

        return "Your order summary:\n".implode("\n", $lines)."\nDelivery charge ({$this->zoneBn()}): ".OrderText::money($deliveryMinor, $cur)."\nTotal (cash on delivery): ".OrderText::money($this->total($index, $deliveryMinor), $cur)
            ."\nName: {$this->name}\nMobile: {$this->phone}\nAddress: {$this->address}\n\nIf everything is correct \"Yes\" write. If you want to change something, tell me.";
    }

    public function zoneBn(): string
    {
        return $this->zone === 'inside' ? 'Inside Dhaka' : 'Outside Dhaka';
    }

    /** @return array<string, mixed> */
    private function fingerprintData(): array
    {
        $items = $this->items;
        usort($items, fn ($a, $b) => strcmp($a['variant_id'], $b['variant_id']));

        return ['i' => $items, 'n' => $this->name, 'p' => $this->phone, 'a' => $this->address, 'z' => $this->zone];
    }
}
