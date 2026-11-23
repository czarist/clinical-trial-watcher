<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Digest;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails a digest edition. The body is rendered from the same stored content as the report
 * page, so both show the same text, facts and links.
 */
final class AiDigestNotification extends Notification
{
    public function __construct(public readonly Digest $digest)
    {
        // The whole email, including the mail template's own text, uses the digest's language.
        $this->locale = $digest->language->value;
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var array<string, mixed> $content */
        $content = $this->digest->content ?? [];

        return (new MailMessage)
            ->subject((string) ($content['subject'] ?? __('digest.subject_fallback')))
            ->markdown('mail.digest', [
                'content' => $content,
                'reportUrl' => rtrim((string) config('app.frontend_url'), '/').'/digests/'.$this->digest->id,
            ]);
    }
}
