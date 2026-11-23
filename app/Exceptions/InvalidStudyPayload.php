<?php

declare(strict_types=1);

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * A study payload cannot be imported, for example because it has no NCT number.
 */
final class InvalidStudyPayload extends InvalidArgumentException
{
    public static function missingNctId(): self
    {
        return new self('The study payload has no protocolSection.identificationModule.nctId.');
    }
}
