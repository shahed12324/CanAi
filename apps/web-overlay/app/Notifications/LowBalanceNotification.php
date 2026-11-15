<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Credits\CreditMath;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class LowBalanceNotification extends Notification
{
    public function __construct(
        private readonly string $workspaceName,
        private readonly string $available,
        private readonly string $url,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(config('brand.name').': Credits are low')
            ->line("\"{$this->workspaceName}\" workspace-now has usable credits ".CreditMath::format($this->available).'.')
            ->action('View credits', $this->url)
            ->line('When credits run out, AI requests will stop.');
    }
}
