<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a registry is read. Documented APIs follow their own terms; downloads and web pages
 * are also subject to the host's robots.txt.
 */
enum AccessMethod: string
{
    case Api = 'api';
    case Download = 'download';
    case Scraping = 'scraping';

    public function honoursRobotsTxt(): bool
    {
        return $this !== self::Api;
    }

    /** Whether a run that parses far fewer records or fields than the last one is suspicious. */
    public function detectsLayoutDrift(): bool
    {
        return $this !== self::Api;
    }
}
