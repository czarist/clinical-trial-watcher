<?php

declare(strict_types=1);

namespace App\Services\ClinicalTrials\Search\Filters;

final class LikePattern
{
    /**
     * A LIKE pattern matching values that contain the text, with wildcards escaped.
     */
    public static function contains(string $text): string
    {
        return '%'.addcslashes($text, '\\%_').'%';
    }
}
