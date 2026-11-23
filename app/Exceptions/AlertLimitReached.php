<?php

declare(strict_types=1);

namespace App\Exceptions;

final class AlertLimitReached extends ApplicationException
{
    public static function forLimit(int $limit): self
    {
        return new self(__('errors.alert_limit', ['limit' => $limit]));
    }

    #[\Override]
    public function status(): int
    {
        return 403;
    }
}
