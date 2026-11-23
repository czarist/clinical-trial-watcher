<?php

declare(strict_types=1);

namespace Tests\Unit\Registries;

use App\Services\Registries\Fetching\RobotsTxt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RobotsTxtTest extends TestCase
{
    private const string AGENT = 'ClinicalTrialWatcher/1.0 (+https://example.org/contact)';

    private const string FILE = <<<'TXT'
        # Example
        User-agent: *
        Disallow: /search
        Allow: /search/about
        Disallow: /*.pdf$
        Crawl-delay: 5

        User-agent: BadBot
        Disallow: /

        User-agent: clinicaltrialwatcher
        User-agent: other
        Disallow: /private
        Allow: /private/public
        TXT;

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function paths(): iterable
    {
        yield 'our own group allows what * disallows' => [self::AGENT, '/search?q=x', true];
        yield 'our own group disallows' => [self::AGENT, '/private/page', false];
        yield 'longest rule wins' => [self::AGENT, '/private/public/1', true];
        yield 'another agent falls back to *' => ['SomeCrawler', '/search?q=x', false];
        yield 'allow beats a shorter disallow' => ['SomeCrawler', '/search/about', true];
        yield 'wildcard with end anchor' => ['SomeCrawler', '/files/report.pdf', false];
        yield 'anchor does not match a longer path' => ['SomeCrawler', '/files/report.pdf.html', true];
        yield 'unlisted path is allowed' => ['SomeCrawler', '/trials/1', true];
        yield 'a blocked agent is blocked everywhere' => ['BadBot/2.0', '/trials/1', false];
    }

    #[DataProvider('paths')]
    public function test_rules_follow_rfc_9309(string $agent, string $path, bool $allowed): void
    {
        $this->assertSame($allowed, RobotsTxt::parse(self::FILE)->allows($agent, $path));
    }

    public function test_crawl_delay_is_read_for_the_matching_group(): void
    {
        $rules = RobotsTxt::parse(self::FILE);

        $this->assertSame(5.0, $rules->crawlDelay('SomeCrawler'));
        $this->assertNull($rules->crawlDelay(self::AGENT));
    }

    public function test_an_empty_file_allows_everything_and_unreachable_disallows_everything(): void
    {
        $this->assertTrue(RobotsTxt::parse('')->allows(self::AGENT, '/anything'));
        $this->assertTrue(RobotsTxt::allowAll()->allows(self::AGENT, '/anything'));
        $this->assertFalse(RobotsTxt::disallowAll()->allows(self::AGENT, '/anything'));
    }
}
