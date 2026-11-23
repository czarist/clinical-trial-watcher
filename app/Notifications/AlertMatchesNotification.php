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
            ->subject("{$this->total} study update(s) for your alert \"{$this->alert->name}\"")
            ->greeting("Alert: {$this->alert->name}")
            ->line("{$this->total} study update(s) match this alert.");

        foreach ($this->matches as $match) {
            $message->line($this->describe($match));
        }

        if ($this->total > $this->matches->count()) {
            $message->line(sprintf('And %d more.', $this->total - $this->matches->count()));
        }

        return $message
            ->action('Open your alerts', "{$frontend}/alerts/{$this->alert->id}")
            ->line('This information comes from the public ClinicalTrials.gov registry. It is not medical advice or a decision about eligibility; talk to a health professional and the study team.');
    }

    private function describe(AlertMatch $match): string
    {
        $trial = $match->trial;
        $title = $trial !== null ? "{$trial->nct_id}: {$trial->brief_title}" : 'Study removed';

        return match ($match->reason) {
            AlertMatchReason::StatusChange => sprintf(
                '[%s] %s (%s → %s)',
                $match->reason->label(),
                $title,
                $match->change?->previous_status ?? 'unknown',
                $match->change?->new_status ?? 'unknown',
            ),
            default => "[{$match->reason->label()}] {$title}",
        };
    }
}
