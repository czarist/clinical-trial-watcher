<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Anzctr;

use App\Exceptions\RegistryRequestFailed;
use App\Services\ClinicalTrials\Import\StudyPage;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\Parsing\HtmlDocument;
use App\Services\Registries\RegistrySource;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Reads ANZCTR trial pages for the trials linked from one listing page.
 *
 * ANZCTR has no API, feed or documented listing URL: its search is an ASP.NET form postback.
 * The listing page is configurable (`registries.options.anzctr.listing_url`) and is parsed for
 * links to trial pages only (an ACTRN in the link or its text); this parser is not verified
 * against a recorded results page. Only that one page is read; following postback pagination
 * or the search's XML export needs ANZCTR's agreement on a feed first.
 *
 * There is no updated-since filter on the registry; an incremental run still reads every
 * listed trial page and returns only those whose "Date last updated" is on or after
 * updatedSince. The page token is the position in the listing.
 */
final readonly class AnzctrClient implements StudyRegistryClient
{
    public const string DEFAULT_LISTING_URL = AnzctrSource::BASE_URL.'/TrialSearch.aspx';

    public function __construct(
        private RegistryFetcher $fetcher,
        private RegistrySource $source,
    ) {}

    public function fetchPage(int $pageSize, ?string $pageToken = null, ?CarbonInterface $updatedSince = null): StudyPage
    {
        $position = self::parseToken($pageToken);
        $listingUrl = (string) config('registries.options.'.AnzctrSource::KEY.'.listing_url', self::DEFAULT_LISTING_URL);
        $document = $this->fetcher->get($this->source, $listingUrl);
        $ids = self::parseListing($document->body, $document->contentType);
        $studies = [];

        for ($index = $position, $count = count($ids); $index < $count; $index++) {
            if (count($studies) >= max(1, $pageSize)) {
                return new StudyPage($studies, (string) $index);
            }

            $trial = $this->trial($ids[$index]);

            if (self::changedSince($trial['updated_on'], $updatedSince)) {
                $studies[] = $trial;
            }
        }

        return new StudyPage($studies, null);
    }

    public static function parseToken(?string $token): int
    {
        if ($token === null || $token === '') {
            return 0;
        }

        if (preg_match('/^\d+$/', $token) !== 1) {
            throw new InvalidArgumentException("Invalid ANZCTR page token [{$token}].");
        }

        return (int) $token;
    }

    /**
     * ACTRN numbers of the trials linked from a listing page, in order, without repeats.
     * Unverified selectors: any link whose ACTRN query parameter or text is an ACTRN number.
     *
     * @return list<string>
     *
     * @throws RegistryRequestFailed when the page is not an ANZCTR page
     */
    public static function parseListing(string $html, ?string $contentType = null): array
    {
        if ($contentType !== null && ! str_contains(strtolower($contentType), 'html')) {
            throw RegistryRequestFailed::invalidPayload();
        }

        $page = new HtmlDocument($html);

        if (! str_starts_with((string) $page->text('//title'), 'ANZCTR')) {
            throw RegistryRequestFailed::invalidPayload();
        }

        $ids = [];

        foreach ($page->nodes('//a[@href]') as $link) {
            $href = (string) $page->text('./@href', $link);
            $id = preg_match('/[?&]ACTRN=(\d{14})(?:&|$)/i', $href, $match) === 1
                ? AnzctrSource::canonicalId('ACTRN'.$match[1])
                : AnzctrSource::canonicalId($page->text('.', $link));

            if ($id !== null) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    private static function changedSince(?string $updatedOn, ?CarbonInterface $updatedSince): bool
    {
        if ($updatedSince === null || $updatedOn === null) {
            return true;
        }

        return CarbonImmutable::parse($updatedOn)->greaterThanOrEqualTo($updatedSince->toImmutable()->startOfDay());
    }

    /**
     * @return array{id: string, url: string, updated_on: ?string, html: string}
     */
    private function trial(string $id): array
    {
        $url = AnzctrSource::trialUrl($id);
        $document = $this->fetcher->get($this->source, $url);
        $page = new HtmlDocument($document->body);
        $number = AnzctrSource::canonicalId($page->text("//*[@id='ctl00_body_CXACTRNUMBER']"));

        if ($number === null) {
            throw new RegistryRequestFailed("The ANZCTR page {$url} does not contain a trial record.");
        }

        return [
            'id' => $number,
            'url' => $url,
            'updated_on' => AnzctrMapper::dayFirstDate($page->text("//*[@id='ctl00_body_CXUPDATEDATE']")),
            'html' => AnzctrMapper::withoutPersonalData($document->body),
        ];
    }
}
