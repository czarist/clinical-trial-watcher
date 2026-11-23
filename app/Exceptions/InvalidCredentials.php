<?php

declare(strict_types=1);

namespace App\Exceptions;

final class InvalidCredentials extends ApplicationException
{
    public function __construct()
    {
        parent::__construct('Invalid email or password.');
    }

    #[\Override]
    public function status(): int
    {
        return 401;
    }
}
