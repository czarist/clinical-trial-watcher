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
            ->subject("Invitation to the team {$this->team->name}")
            ->line("{$this->inviter->name} invited you to the team \"{$this->team->name}\" on Clinical Trial Watcher.")
            ->line('Members share the team\'s saved searches and AI digest reports. Nothing changes until you accept.')
            ->action('Review the invitation', rtrim((string) config('app.frontend_url'), '/').'/teams');
    }
}
