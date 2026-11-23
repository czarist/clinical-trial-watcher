<?php

declare(strict_types=1);

namespace App\Exceptions;

final class AlertLimitReached extends ApplicationException
{
    public static function forLimit(int $limit): self
    {
        return new self("You can keep up to {$limit} saved searches. Delete one to create another.");
    }

    #[\Override]
    public function status(): int
    {
        return 403;
    }
}
