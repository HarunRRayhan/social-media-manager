<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvitedToTeam extends Notification
{
    public function __construct(private string $team, private string $url) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Join :team', ['team' => $this->team]))
            ->line(__('You have been invited to join :team on :app.', ['team' => $this->team, 'app' => config('app.name')]))
            ->action(__('Accept invitation'), $this->url)
            ->line(__('The invitation expires in 7 days. If you did not expect it, ignore this email.'));
    }
}
