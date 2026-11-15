<?php

declare(strict_types=1);

namespace App\Outreach;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/** Sends one plain-text mail over the account's own SMTP server. Never logs the password or the mail body. */
final class SymfonyMailTransport implements MailTransport
{
    public function send(array $smtp, array $mail): array
    {
        // checked again at send time: a host that was public when saved must not have been re-pointed inside our network
        if (! HostGuard::allowed($smtp['host'], HostGuard::resolve(...))) {
            throw new TransportFailure('smtp_unreachable', false);
        }
        $transport = new EsmtpTransport($smtp['host'], $smtp['port'], $smtp['security'] === 'tls');
        $transport->setUsername($smtp['username']);
        $transport->setPassword($smtp['password']);

        $domain = substr(strrchr($mail['from_email'], '@') ?: '@localhost', 1);
        $id = bin2hex(random_bytes(12)).'@'.$domain;
        $email = (new Email)
            ->from(new Address($mail['from_email'], (string) ($mail['from_name'] ?? '')))
            ->to($mail['to'])
            ->subject($mail['subject'])
            ->text($mail['body']);
        foreach ($mail['attachments'] ?? [] as $a) {
            $email->attach($a['bytes'], $a['name'], preg_match('/\A[a-z]+\/[a-z0-9][a-z0-9.+-]*\z/i', $a['mime']) === 1 ? $a['mime'] : 'application/octet-stream');
        }
        $email->getHeaders()->addIdHeader('Message-ID', $id);
        foreach ($mail['headers'] as $k => $v) {
            $email->getHeaders()->addTextHeader($k, $v);
        }

        try {
            $transport->send($email);
        } catch (TransportExceptionInterface $e) {
            $m = $e->getMessage();
            // auth problems and outages are about the account (the mail is retried later); only a refusal of this recipient is final
            $code = preg_match('/\b(535|534|530)\b/', $m) ? 'smtp_auth' : (preg_match('/\b(550|551|553|554)\b/', $m) ? 'smtp_rejected' : 'smtp_unreachable');
            throw new TransportFailure($code, $code === 'smtp_rejected');
        } finally {
            $transport->stop();
        }

        return ['message_id' => $id, 'provider_id' => null, 'thread_id' => null];
    }
}
