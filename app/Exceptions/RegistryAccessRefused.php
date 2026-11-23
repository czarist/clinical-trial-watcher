<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A registry request that must not be made or retried: the source's legal review does not
 * allow automated access, robots.txt disallows the URL, or the server blocked the client
 * (login, captcha, HTTP 401/403). Never work around it; ask the registry for permission or
 * an export instead.
 */
final class RegistryAccessRefused extends RuntimeException
{
    private const string ADVICE = 'Ask the registry for permission or a data export instead of retrying.';

    public static function notAllowed(string $source): self
    {
        return new self("Automated access to {$source} is not allowed by its legal review. ".self::ADVICE);
    }

    public static function byRobots(string $source, string $url): self
    {
        return new self("robots.txt disallows {$url} for {$source}. ".self::ADVICE);
    }

    public static function byServer(string $source, int $status): self
    {
        return new self("{$source} blocked the request (HTTP {$status}, login or captcha). ".self::ADVICE);
    }

    public static function missingContact(): self
    {
        return new self('Set REGISTRY_CONTACT (a URL or email address for registry operators) before reading registries by download or scraping.');
    }
}
