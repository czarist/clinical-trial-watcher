<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Team;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a registered user they were invited to a team; they accept or decline in the web app.
 */
final class TeamInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Team $team,
        public readonly User $inviter,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('mail.team_invitation.subject', ['team' => $this->team->name]))
            ->line(__('mail.team_invitation.intro', ['inviter' => $this->inviter->name, 'team' => $this->team->name]))
            ->line(__('mail.team_invitation.body'))
            ->action(__('mail.team_invitation.action'), rtrim((string) config('app.frontend_url'), '/').'/teams');
    }
}
