<?php

declare(strict_types=1);

namespace App\Enums;

enum DigestLanguage: string
{
    case English = 'en';
    case BrazilianPortuguese = 'pt_BR';

    /**
     * Name given to the model when it writes in this language.
     */
    public function promptName(): string
    {
        return match ($this) {
            self::English => 'English',
            self::BrazilianPortuguese => 'Brazilian Portuguese',
        };
    }
}
