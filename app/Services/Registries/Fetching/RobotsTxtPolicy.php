<?php

declare(strict_types=1);

namespace App\Services\Registries\Fetching;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Reads and caches each host's robots.txt. As RFC 9309 requires, a missing file (4xx)
 * allows everything and an unreachable one (5xx or no connection) disallows everything;
 * the second case is cached briefly so the host is asked again soon.
 */
final readonly class RobotsTxtPolicy
{
    private const int UNREACHABLE_TTL_SECONDS = 3600;

    public function __construct(
        private HttpFactory $http,
        private Cache $cache,
        private string $userAgent,
        private int $cacheHours,
        private int $timeoutSeconds,
    ) {}

    public function allows(string $url): bool
    {
        $path = (parse_url($url, PHP_URL_PATH) ?: '/').(($query = parse_url($url, PHP_URL_QUERY)) ? '?'.$query : '');

        return $this->rules($url)->allows($this->userAgent, $path);
    }

    /** Crawl-delay for this client in milliseconds, if the host sets one. */
    public function crawlDelayMs(string $url): ?int
    {
        $delay = $this->rules($url)->crawlDelay($this->userAgent);

        return $delay !== null ? (int) round($delay * 1000) : null;
    }

    private function rules(string $url): RobotsTxt
    {
        $origin = parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST)
            .(($port = parse_url($url, PHP_URL_PORT)) ? ':'.$port : '');
        $key = 'robots-txt:'.sha1($origin);
        $cached = $this->cache->get($key);

        if (is_array($cached)) {
            return $cached['reachable'] ? RobotsTxt::parse($cached['content']) : RobotsTxt::disallowAll();
        }

        [$reachable, $content] = $this->fetch($origin.'/robots.txt');
        $this->cache->put(
            $key,
            ['reachable' => $reachable, 'content' => $content],
            $reachable ? $this->cacheHours * 3600 : self::UNREACHABLE_TTL_SECONDS,
        );

        return $reachable ? RobotsTxt::parse($content) : RobotsTxt::disallowAll();
    }

    /**
     * @return array{bool, string} whether the host answered, and the rules ('' allows everything)
     */
    private function fetch(string $robotsUrl): array
    {
        try {
            $response = $this->http->withUserAgent($this->userAgent)->timeout($this->timeoutSeconds)->get($robotsUrl);
        } catch (ConnectionException) {
            return [false, ''];
        }

        return match (true) {
            $response->successful() => [true, mb_substr($response->body(), 0, 500_000)],
            $response->status() >= 500 => [false, ''],
            default => [true, ''],
        };
    }
}
