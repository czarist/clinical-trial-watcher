<?php

declare(strict_types=1);

namespace Tests\Feature\Registries;

use App\Enums\AccessMethod;
use App\Exceptions\RegistryAccessRefused;
use App\Exceptions\RegistryRequestFailed;
use App\Models\RegistryPage;
use App\Services\Registries\Fetching\RegistryFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Fakes\FakeRegistrySource;
use Tests\TestCase;

final class RegistryFetcherTest extends TestCase
{
    use RefreshDatabase;

    private const string PAGE = 'https://registry.test/trials?page=1';

    private FakeRegistrySource $source;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Sleep::fake();
        Http::preventStrayRequests();
        config([
            'registries.fetching.contact' => 'https://watcher.example/contact',
            'registries.fetching.min_interval_ms' => 1000,
            'registries.fetching.retry' => ['times' => 3, 'sleep_ms' => 500, 'max_sleep_ms' => 4000],
        ]);
        FakeRegistrySource::reset();
        FakeRegistrySource::$method = AccessMethod::Scraping;
        $this->source = new FakeRegistrySource;
    }

    private function fetcher(): RegistryFetcher
    {
        return $this->app->make(RegistryFetcher::class);
    }

    public function test_requests_identify_the_client_and_store_the_page(): void
    {
        Http::fake([
            'registry.test/robots.txt' => Http::response("User-agent: *\nDisallow: /private"),
            'registry.test/trials*' => Http::response('<html>list</html>', 200, ['ETag' => '"v1"', 'Content-Type' => 'text/html']),
        ]);

        $document = $this->fetcher()->get($this->source, 'https://registry.test/trials', ['page' => 1, 'empty' => null]);

        $this->assertSame('<html>list</html>', $document->body);
        $this->assertSame(self::PAGE, $document->url);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::PAGE
            && $request->header('User-Agent')[0] === 'ClinicalTrialWatcher/1.0 (+https://watcher.example/contact)');
        $page = RegistryPage::query()->sole();
        $this->assertSame('"v1"', $page->etag);
        Storage::disk('local')->assertExists($page->storage_path);
    }

    public function test_an_unchanged_page_is_answered_from_the_stored_copy(): void
    {
        Http::fake([
            'registry.test/robots.txt' => Http::response('', 404),
            'registry.test/trials*' => Http::sequence()
                ->push('<html>v1</html>', 200, ['ETag' => '"v1"', 'Last-Modified' => 'Mon, 23 Nov 2026 10:00:00 GMT'])
                ->push('', 304),
        ]);

        $this->fetcher()->get($this->source, self::PAGE);
        $second = $this->fetcher()->get($this->source, self::PAGE);

        $this->assertTrue($second->notModified);
        $this->assertSame('<html>v1</html>', $second->body);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('If-None-Match', '"v1"')
            && $request->hasHeader('If-Modified-Since', 'Mon, 23 Nov 2026 10:00:00 GMT'));
    }

    public function test_replay_reads_stored_pages_without_any_request(): void
    {
        Http::fake([
            'registry.test/robots.txt' => Http::response('', 404),
            'registry.test/trials*' => Http::response('<html>v1</html>'),
        ]);
        $fetcher = $this->fetcher();
        $fetcher->get($this->source, self::PAGE);
        $requests = count(Http::recorded());

        $fetcher->replayFromCache();
        $replayed = $fetcher->get($this->source, self::PAGE);

        $this->assertTrue($replayed->fromCache);
        $this->assertSame('<html>v1</html>', $replayed->body);
        $this->assertCount($requests, Http::recorded());

        $this->expectException(RegistryRequestFailed::class);
        $fetcher->get($this->source, 'https://registry.test/trials?page=2');
    }

    public function test_post_searches_send_json_and_replay_by_body(): void
    {
        FakeRegistrySource::$method = AccessMethod::Api;
        Http::fake(['registry.test/search' => Http::sequence()->push('{"page":1}')->push('{"page":2}')]);
        $fetcher = $this->fetcher();

        $fetcher->post($this->source, 'https://registry.test/search', ['page' => 1]);
        $fetcher->post($this->source, 'https://registry.test/search', ['page' => 2]);
        $fetcher->replayFromCache();

        $this->assertSame('{"page":2}', $fetcher->post($this->source, 'https://registry.test/search', ['page' => 2])->body);
        $this->assertSame('{"page":1}', $fetcher->post($this->source, 'https://registry.test/search', ['page' => 1])->body);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['page'] === 1 && ! $request->hasHeader('If-None-Match'));
        Http::assertSentCount(2);
    }

    public function test_secret_query_parameters_are_sent_but_never_stored(): void
    {
        FakeRegistrySource::$method = AccessMethod::Api;
        Http::fake(['registry.test/api*' => Http::response('{"items":[]}')]);

        $document = $this->fetcher()->get($this->source, 'https://registry.test/api', ['page' => 1], [], ['serviceKey' => 'secret-key']);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://registry.test/api?page=1&serviceKey=secret-key');
        $this->assertSame('https://registry.test/api?page=1', $document->url);
        $this->assertStringNotContainsString('secret-key', (string) RegistryPage::query()->sole()->url);
    }

    public function test_server_errors_and_rate_limits_are_retried_with_bounded_backoff(): void
    {
        Http::fake([
            'registry.test/robots.txt' => Http::response('', 404),
            'registry.test/trials*' => Http::sequence()
                ->push('busy', 503)
                ->push('slow down', 429, ['Retry-After' => '120'])
                ->push('<html>ok</html>'),
        ]);

        $this->assertSame('<html>ok</html>', $this->fetcher()->get($this->source, self::PAGE)->body);

        // 500 ms backoff after the 503, Retry-After capped at 4 s after the 429.
        Sleep::assertSlept(fn ($duration): bool => (int) $duration->totalMilliseconds === 500, 1);
        Sleep::assertSlept(fn ($duration): bool => (int) $duration->totalMilliseconds === 4000, 1);
    }

    public function test_retries_stop_after_the_configured_attempts(): void
    {
        Http::fake([
            'registry.test/robots.txt' => Http::response('', 404),
            'registry.test/trials*' => Http::response('down', 503),
        ]);

        try {
            $this->fetcher()->get($this->source, self::PAGE);
            $this->fail('The request should have failed.');
        } catch (RegistryRequestFailed $exception) {
            $this->assertStringContainsString('503', $exception->getMessage());
        }

        Http::assertSentCount(4); // robots.txt + 3 attempts
    }

    public function test_requests_to_one_registry_keep_the_minimum_interval(): void
    {
        Http::fake([
            'registry.test/robots.txt' => Http::response("User-agent: *\nCrawl-delay: 3"),
            'registry.test/*' => Http::response('<html>ok</html>'),
        ]);

        $this->fetcher()->get($this->source, 'https://registry.test/a');
        $this->fetcher()->get($this->source, 'https://registry.test/b');

        // The robots.txt Crawl-delay (3 s) is stricter than the configured 1 s.
        Sleep::assertSlept(fn ($duration): bool => $duration->totalMilliseconds > 2500 && $duration->totalMilliseconds <= 3000, 1);
    }

    public function test_robots_txt_disallowing_the_url_refuses_the_request(): void
    {
        Http::fake(['registry.test/robots.txt' => Http::response("User-agent: *\nDisallow: /trials")]);

        $this->expectException(RegistryAccessRefused::class);

        try {
            $this->fetcher()->get($this->source, self::PAGE);
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_an_unreachable_robots_txt_disallows_everything(): void
    {
        Http::fake(['registry.test/robots.txt' => Http::response('', 503)]);

        $this->expectException(RegistryAccessRefused::class);
        $this->fetcher()->get($this->source, self::PAGE);
    }

    public function test_documented_apis_are_not_checked_against_robots_txt(): void
    {
        FakeRegistrySource::$method = AccessMethod::Api;
        Http::fake(['registry.test/trials*' => Http::response('{"items":[]}')]);

        $this->fetcher()->get($this->source, self::PAGE);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'robots.txt'));
    }

    public function test_a_source_without_an_approved_legal_review_is_never_requested(): void
    {
        FakeRegistrySource::$allowed = false;
        Http::fake();

        try {
            $this->fetcher()->get($this->source, self::PAGE);
            $this->fail('The request should have been refused.');
        } catch (RegistryAccessRefused $exception) {
            $this->assertStringContainsString('permission', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_scraping_needs_a_contact_address(): void
    {
        config(['registries.fetching.contact' => null]);
        Http::fake();

        $this->expectException(RegistryAccessRefused::class);
        $this->fetcher()->get($this->source, self::PAGE);
    }

    public function test_blocks_and_captcha_pages_are_not_retried(): void
    {
        Http::fake([
            'registry.test/robots.txt' => Http::response('', 404),
            'registry.test/blocked' => Http::response('Forbidden', 403),
            'registry.test/challenge' => Http::response('<html><title>Attention Required!</title></html>', 200, ['Content-Type' => 'text/html']),
            'registry.test/form' => Http::response('<html><title>Search</title><script src="recaptcha.js"></script></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        foreach (['blocked', 'challenge'] as $path) {
            try {
                $this->fetcher()->get($this->source, "https://registry.test/{$path}");
                $this->fail("{$path} should have been refused.");
            } catch (RegistryAccessRefused) {
                Http::assertSentCount($path === 'blocked' ? 2 : 3);
            }
        }

        // A page that only loads a captcha script for a form is an ordinary page.
        $this->assertStringContainsString('Search', $this->fetcher()->get($this->source, 'https://registry.test/form')->body);
    }
}
