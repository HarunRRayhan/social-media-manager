<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MagicLink extends Notification
{
    private const COPY = [
        'login' => ['Your login link', 'Click below to log in.', 'Log in'],
        'signup' => ['Finish creating your account', 'Click below to create your account.', 'Create account'],
        'confirm' => ['Confirm it is you', 'Click below to confirm and continue.', 'Confirm'],
    ];

    public function __construct(private string $url, private string $type) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        [$subject, $line, $action] = self::COPY[$this->type];

        return (new MailMessage)
            ->subject($subject)
            ->line($line)
            ->action($action, $this->url)
            ->line(__('The link works once and expires in :minutes minutes. If you did not ask for it, ignore this email.', ['minutes' => 15]));
    }
}
