<?php

declare(strict_types=1);

namespace App\Services\Registries\Fetching;

use App\Enums\AccessMethod;
use App\Exceptions\RegistryAccessRefused;
use App\Exceptions\RegistryRequestFailed;
use App\Models\RegistryPage;
use App\Services\Registries\RegistrySource;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;

/**
 * Polite HTTP access shared by every registry importer:
 *
 * - an identified user agent with a contact address for the registry's operators;
 * - requests refused when the source's legal review does not allow automated access, and,
 *   for downloads and web pages, when robots.txt disallows the URL;
 * - one request at a time per registry (a cache lock) and a minimum interval between
 *   requests (the larger of the configured one, the source's own and robots.txt Crawl-delay);
 * - bounded retries with exponential backoff for connection failures, HTTP 429 and 5xx,
 *   honouring Retry-After up to a maximum;
 * - conditional requests (If-None-Match / If-Modified-Since) and a stored copy of every
 *   body, so an unchanged page is not downloaded again and a parser fix can replay the
 *   stored pages (replayFromCache) without any request;
 * - HTTP 401/403 and captcha pages end the run: they are never retried or worked around.
 */
final class RegistryFetcher
{
    private bool $replay = false;

    /**
     * @param  array{times: int, sleep_ms: int, max_sleep_ms: int}  $retry
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly Cache $cache,
        private readonly RobotsTxtPolicy $robots,
        private readonly RawPageStore $store,
        private readonly string $userAgent,
        private readonly ?string $contact,
        private readonly int $minIntervalMs,
        private readonly int $timeoutSeconds,
        private readonly array $retry,
    ) {}

    /** Serve every request from stored copies, without network access. */
    public function replayFromCache(bool $replay = true): void
    {
        $this->replay = $replay;
    }

    public function isReplaying(): bool
    {
        return $this->replay;
    }

    /** The user agent sent to registries, including the contact address when configured. */
    public function userAgent(): string
    {
        return $this->contact !== null && $this->contact !== '' ? "{$this->userAgent} (+{$this->contact})" : $this->userAgent;
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @param  array<string, string>  $headers
     * @param  array<string, string>  $secretQuery  query parameters sent but never stored or logged (API keys
     *                                             of registries that take them in the query string)
     *
     * @throws RegistryAccessRefused when the request must not be made
     * @throws RegistryRequestFailed when the registry cannot be read after the allowed retries
     */
    public function get(RegistrySource $source, string $url, array $query = [], array $headers = [], array $secretQuery = []): FetchedDocument
    {
        return $this->request($source, self::withQuery($url, $query), null, $headers, $secretQuery);
    }

    /**
     * A POST with a JSON body, for search APIs that take one. It follows the same rules as get()
     * without conditional headers; the stored copy is keyed by the URL and the body, so replay works.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     *
     * @throws RegistryAccessRefused when the request must not be made
     * @throws RegistryRequestFailed when the registry cannot be read after the allowed retries
     */
    public function post(RegistrySource $source, string $url, array $payload, array $headers = []): FetchedDocument
    {
        return $this->request($source, $url, $payload, $headers, []);
    }

    /**
     * @param  array<string, mixed>|null  $payload  JSON body of a POST, or null for a GET
     * @param  array<string, string>  $headers
     * @param  array<string, string>  $secretQuery
     */
    private function request(RegistrySource $source, string $url, ?array $payload, array $headers, array $secretQuery): FetchedDocument
    {
        $storeKey = $payload === null ? $url : $url.'#'.hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $stored = $this->store->find($source->key(), $storeKey);

        if ($this->replay) {
            return $this->fromStore($stored, $url, fromCache: true) ?? throw RegistryRequestFailed::notCached($url);
        }

        $access = $source->access();

        if (! $access->automatedAccessAllowed) {
            throw RegistryAccessRefused::notAllowed($source->name());
        }

        if ($access->method !== AccessMethod::Api && ($this->contact === null || $this->contact === '')) {
            throw RegistryAccessRefused::missingContact();
        }

        if ($access->method->honoursRobotsTxt() && ! $this->robots->allows($url)) {
            throw RegistryAccessRefused::byRobots($source->name(), $url);
        }

        $interval = max(
            $this->minIntervalMs,
            $access->minIntervalMs ?? 0,
            $access->method->honoursRobotsTxt() ? ($this->robots->crawlDelayMs($url) ?? 0) : 0,
        );

        return $this->exclusively(
            $source->key(),
            fn (): FetchedDocument => $this->send($source, $url, self::withQuery($url, $secretQuery), $storeKey, $payload, $headers, $stored, $interval),
        );
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, string>  $headers
     */
    private function send(RegistrySource $source, string $url, string $requestUrl, string $storeKey, ?array $payload, array $headers, ?RegistryPage $stored, int $interval): FetchedDocument
    {
        $conditional = $payload !== null ? [] : array_filter([
            'If-None-Match' => $stored?->etag,
            'If-Modified-Since' => $stored?->last_modified,
        ]);

        for ($attempt = 1; ; $attempt++) {
            $this->throttle($source->key(), $interval);

            try {
                $response = $this->http
                    ->withUserAgent($this->userAgent())
                    ->withHeaders($headers + $conditional)
                    ->timeout($this->timeoutSeconds)
                    ->send($payload === null ? 'GET' : 'POST', $requestUrl, $payload === null ? [] : ['json' => $payload]);
            } catch (ConnectionException $exception) {
                if ($attempt >= $this->retry['times']) {
                    // The message can contain the request URL; keep secret query parameters out of it.
                    throw $requestUrl === $url
                        ? RegistryRequestFailed::fromConnection($exception)
                        : new RegistryRequestFailed('Could not connect to the registry: '.str_replace($requestUrl, $url, $exception->getMessage()));
                }

                $this->backoff($attempt, null);

                continue;
            }

            if ($response->status() === 304 && $stored !== null) {
                $this->store->touch($stored);

                return $this->fromStore($stored, $url, notModified: true) ?? throw RegistryRequestFailed::notCached($url);
            }

            if ($response->status() === 429 || $response->serverError()) {
                if ($attempt >= $this->retry['times']) {
                    throw RegistryRequestFailed::fromStatus($response->status());
                }

                $this->backoff($attempt, $response->header('Retry-After'));

                continue;
            }

            if (in_array($response->status(), [401, 403], true) || self::isChallenge($response)) {
                throw RegistryAccessRefused::byServer($source->name(), $response->status());
            }

            if (! $response->successful()) {
                throw RegistryRequestFailed::fromStatus($response->status());
            }

            $this->store->put($source->key(), $storeKey, $response->body(), [
                'status' => $response->status(),
                'etag' => $response->header('ETag') ?: null,
                'last_modified' => $response->header('Last-Modified') ?: null,
                'content_type' => $response->header('Content-Type') ?: null,
            ]);

            return new FetchedDocument($url, $response->body(), $response->header('Content-Type') ?: null, CarbonImmutable::now());
        }
    }

    private function fromStore(?RegistryPage $page, string $url, bool $notModified = false, bool $fromCache = false): ?FetchedDocument
    {
        $body = $page !== null ? $this->store->body($page) : null;

        return $body !== null
            ? new FetchedDocument($url, $body, $page->content_type, CarbonImmutable::parse($page->fetched_at), $notModified, $fromCache)
            : null;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function exclusively(string $source, callable $callback): mixed
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            return $callback();
        }

        $lockSeconds = $this->timeoutSeconds * max(1, $this->retry['times']) + intdiv($this->retry['max_sleep_ms'], 1000) + 60;

        return $store->lock("registry-fetch:{$source}", $lockSeconds)->block($lockSeconds, $callback);
    }

    private function throttle(string $source, int $intervalMs): void
    {
        $key = "registry-fetch:last:{$source}";
        $last = $this->cache->get($key);
        $now = (int) (microtime(true) * 1000);

        if (is_int($last) && $now - $last < $intervalMs) {
            Sleep::for($intervalMs - ($now - $last))->milliseconds();
        }

        $this->cache->put($key, (int) (microtime(true) * 1000), 3600);
    }

    private function backoff(int $attempt, ?string $retryAfter): void
    {
        $delay = is_numeric($retryAfter)
            ? (int) $retryAfter * 1000
            : $this->retry['sleep_ms'] * (2 ** ($attempt - 1));

        Sleep::for(min($delay, $this->retry['max_sleep_ms']))->milliseconds();
    }

    private static function isChallenge(Response $response): bool
    {
        if (! str_contains(strtolower((string) $response->header('Content-Type')), 'html')) {
            return false;
        }

        // Challenge pages, not ordinary pages that merely load a captcha script for a form.
        return preg_match(
            '/cf-chl|challenge-platform|are you a robot|verify you are human|<title>[^<]*(captcha|access denied|attention required)/i',
            substr($response->body(), 0, 20_000),
        ) === 1;
    }

    /**
     * @param  array<string, scalar|null>  $query
     */
    private static function withQuery(string $url, array $query): string
    {
        $query = array_filter($query, static fn (mixed $value): bool => $value !== null);

        return $query === [] ? $url : $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query);
    }
}
