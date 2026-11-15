<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Outreach\MailTransport;
use App\Outreach\TransportFailure;

final class FakeMailTransport implements MailTransport
{
    public array $sent = [];

    /** @var array<string, string> to => failure code */
    public array $fail = [];

    public ?string $failAll = null;

    public function send(array $smtp, array $mail): array
    {
        $code = $this->failAll ?? ($this->fail[$mail['to']] ?? null);
        if ($code !== null) {
            throw new TransportFailure($code, $code === 'smtp_rejected');
        }
        $this->sent[] = ['smtp' => $smtp, 'mail' => $mail];

        $n = count($this->sent);

        return ['message_id' => 'id'.$n.'@agency.test', 'provider_id' => ($smtp['type'] ?? 'smtp') === 'smtp' ? null : 'prov'.$n, 'thread_id' => ($smtp['type'] ?? 'smtp') === 'gmail' ? 'thr'.$n : null];
    }
}
