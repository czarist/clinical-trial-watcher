<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\AlertMatchReason;
use App\Models\Alert;
use App\Models\AlertMatch;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

final class AlertMatchesNotification extends Notification
{
    use Queueable;

    /**
     * @param  Collection<int, AlertMatch>  $matches  the matches listed in the message
     * @param  int  $total  every pending match, including those not listed
     */
    public function __construct(
        public readonly Alert $alert,
        public readonly Collection $matches,
        public readonly int $total,
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
        $frontend = rtrim((string) config('app.frontend_url'), '/');
        $message = (new MailMessage)
            ->subject(trans_choice('mail.alert_matches.subject', $this->total, ['count' => $this->total, 'name' => $this->alert->name]))
            ->greeting(__('mail.alert_matches.greeting', ['name' => $this->alert->name]))
            ->line(trans_choice('mail.alert_matches.intro', $this->total, ['count' => $this->total]));

        foreach ($this->matches as $match) {
            $message->line($this->describe($match));
        }

        if ($this->total > $this->matches->count()) {
            $message->line(__('mail.alert_matches.more', ['count' => $this->total - $this->matches->count()]));
        }

        return $message
            ->action(__('mail.alert_matches.action'), "{$frontend}/alerts/{$this->alert->id}")
            ->line(__('mail.notice'));
    }

    private function describe(AlertMatch $match): string
    {
        $trial = $match->trial;
        $title = $trial !== null ? "{$trial->nct_id}: {$trial->brief_title}" : __('mail.alert_matches.study_removed');

        return match ($match->reason) {
            AlertMatchReason::StatusChange => sprintf(
                '[%s] %s (%s → %s)',
                $match->reason->label(),
                $title,
                $match->change?->previous_status ?? __('mail.alert_matches.unknown'),
                $match->change?->new_status ?? __('mail.alert_matches.unknown'),
            ),
            default => "[{$match->reason->label()}] {$title}",
        };
    }
}
