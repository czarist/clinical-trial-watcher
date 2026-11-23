<?php

declare(strict_types=1);

namespace App\Exceptions;

final class AlertLimitReached extends ApplicationException
{
    public static function forLimit(int $limit): self
    {
        return new self("Your plan allows {$limit} alert(s). Upgrade your plan or delete an alert to create a new one.");
    }

    #[\Override]
    public function status(): int
    {
        return 403;
    }
}
