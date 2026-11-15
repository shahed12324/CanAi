<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class InvitationNotification extends Notification
{
    public function __construct(
        private readonly string $workspaceName,
        private readonly string $role,
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
            ->subject(config('brand.name').': "'.$this->workspaceName.'" workspace-Invitation to join')
            ->line("You \"{$this->workspaceName}\" workspace-At {$this->role} has been invited as.")
            ->action('View invitation', $this->url)
            ->line('The link stays valid for 7 days. If you do not want the invitation, ignore this email.');
    }
}
