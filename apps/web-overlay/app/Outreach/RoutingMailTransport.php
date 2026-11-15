<?php

declare(strict_types=1);

namespace App\Outreach;

final class RoutingMailTransport implements MailTransport
{
    public function __construct(private readonly MailTransport $smtp, private readonly GmailTransport $gmail, private readonly GraphTransport $graph) {}

    public function send(array $conn, array $mail): array
    {
        return match ($conn['type'] ?? 'smtp') {
            'gmail' => $this->gmail->send($conn, $mail),
            'graph' => $this->graph->send($conn, $mail),
            default => $this->smtp->send($conn, $mail),
        };
    }
}
