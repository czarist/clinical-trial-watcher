<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Isrctn;

use App\Exceptions\RegistryRequestFailed;
use App\Services\ClinicalTrials\Import\StudyPage;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\RegistrySource;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;

/**
 * Reads ISRCTN records through the query endpoint of its XML API.
 *
 * The API has no offset parameter: a query returns at most `limit` records and the total
 * count. Records are therefore read in windows of their last-edited time
 * (`lastEdited GE start AND lastEdited LT end`, indexed to the second): a window holding
 * more records than one response is narrowed in proportion to the total count and asked
 * again, and the next window is sized from how full the last one was. The page token is
 * the next window's start and length, so every page is a complete window and the walk
 * ends at tomorrow (UTC). A full import starts at `full_import_from`; an incremental one
 * at the given updated-since date.
 */
final class IsrctnClient implements StudyRegistryClient
{
    public const string XML_NAMESPACE = 'http://www.67bricks.com/isrctn';

    /** Records requested at once when a one-second window still overflows. */
    private const int SINGLE_SECOND_MAX = 1000;

    /** Bound on the narrowing attempts of one page. */
    private const int MAX_ATTEMPTS = 40;

    private const string TIME_FORMAT = 'Y-m-d\TH:i:s';

    public function __construct(
        private readonly RegistryFetcher $fetcher,
        private readonly RegistrySource $source,
        private readonly string $baseUrl = 'https://www.isrctn.com',
        private readonly string $fullImportFrom = '1990-01-01',
        private readonly int $maxWindowDays = 366,
        private readonly int $maxRecordsPerRequest = 100,
    ) {}

    public function fetchPage(int $pageSize, ?string $pageToken = null, ?CarbonInterface $updatedSince = null): StudyPage
    {
        $until = CarbonImmutable::now('UTC')->addDay()->startOfDay();
        $maxWindow = max(1, $this->maxWindowDays) * 86_400;

        [$start, $window] = $pageToken !== null && $pageToken !== ''
            ? self::decodeToken($pageToken)
            : [$this->firstStart($updatedSince), $maxWindow];

        if ($start->greaterThanOrEqualTo($until)) {
            return new StudyPage([], null);
        }

        $limit = max(1, min($pageSize, $this->maxRecordsPerRequest));

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $end = $start->addSeconds($window)->min($until);
            $seconds = (int) $start->diffInSeconds($end, true);
            $listing = $this->query($start, $end, $limit);
            $returned = count($listing['trials']);

            if ($returned >= $listing['total']) {
                $next = $end->greaterThanOrEqualTo($until)
                    ? null
                    : self::encodeToken($end, self::nextWindow($seconds, $returned, $limit, $maxWindow));

                return new StudyPage($listing['payloads'], $next);
            }

            if ($seconds <= 1) {
                if ($limit < $listing['total'] && $listing['total'] <= self::SINGLE_SECOND_MAX) {
                    $limit = $listing['total'];

                    continue;
                }

                throw new RegistryRequestFailed(sprintf(
                    'ISRCTN returned %d of %d records edited at %s; the window cannot be split further.',
                    $returned,
                    $listing['total'],
                    $start->format(self::TIME_FORMAT),
                ));
            }

            // Narrow the window in proportion to how much of it fitted, with a margin.
            $window = $returned > 0
                ? max(1, intdiv($seconds * $returned * 4, $listing['total'] * 5))
                : max(1, intdiv($seconds, 2));
        }

        throw new RegistryRequestFailed('ISRCTN windows could not be narrowed to fit one response.');
    }

    /**
     * The query endpoint's response: the total count and each <fullTrial> as its own XML document.
     *
     * @return array{total: int, trials: list<string>}
     *
     * @throws RegistryRequestFailed when the body is not an ISRCTN result list
     */
    public static function parseListing(string $xml): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = trim($xml) !== '' && $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->documentElement;

        if (! $loaded || $root === null || $root->localName !== 'allTrials' || $root->namespaceURI !== self::XML_NAMESPACE
            || ! ctype_digit($root->getAttribute('totalCount'))) {
            throw RegistryRequestFailed::invalidPayload();
        }

        $trials = [];

        foreach ($root->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === 'fullTrial' && $node->namespaceURI === self::XML_NAMESPACE) {
                // Imported into its own document so the namespace declaration travels with it.
                $single = new DOMDocument;
                $single->appendChild($single->importNode($node, true));
                $trials[] = (string) $single->saveXML($single->documentElement);
            }
        }

        return ['total' => (int) $root->getAttribute('totalCount'), 'trials' => $trials];
    }

    /**
     * @return array{0: CarbonImmutable, 1: int} the window's start and length in seconds
     */
    public static function decodeToken(string $token): array
    {
        $parts = explode('|', $token);
        $start = count($parts) === 2 ? CarbonImmutable::createFromFormat('!'.self::TIME_FORMAT, $parts[0], 'UTC') : null;

        if (! $start instanceof CarbonImmutable || $start->format(self::TIME_FORMAT) !== $parts[0] || ! ctype_digit($parts[1]) || (int) $parts[1] < 1) {
            throw new InvalidArgumentException("Invalid ISRCTN page token [{$token}].");
        }

        return [$start, (int) $parts[1]];
    }

    public static function encodeToken(CarbonInterface $start, int $window): string
    {
        return $start->format(self::TIME_FORMAT).'|'.$window;
    }

    /** Length of the next window, aiming at about 80% of a response. */
    public static function nextWindow(int $seconds, int $returned, int $limit, int $maxWindow): int
    {
        $target = $returned > 0 ? intdiv($seconds * $limit * 4, $returned * 5) : $seconds * 4;

        return max(1, min($target, $seconds * 4, $maxWindow));
    }

    /**
     * @return array{total: int, trials: list<string>, payloads: list<array{xml: string, url: string}>}
     */
    private function query(CarbonImmutable $start, CarbonImmutable $end, int $limit): array
    {
        $document = $this->fetcher->get($this->source, rtrim($this->baseUrl, '/').'/api/query/format/default', [
            'q' => sprintf('lastEdited GE %s AND lastEdited LT %s', $start->format(self::TIME_FORMAT), $end->format(self::TIME_FORMAT)),
            'limit' => $limit,
        ], ['Accept' => 'application/xml']);

        $listing = self::parseListing($document->body);
        $listing['payloads'] = array_map(
            fn (string $xml): array => ['xml' => $xml, 'url' => $this->recordApiUrl($xml) ?? $document->url],
            $listing['trials'],
        );

        return $listing;
    }

    private function recordApiUrl(string $xml): ?string
    {
        $id = IsrctnMapper::idFromXml($xml);

        return $id !== null ? rtrim($this->baseUrl, '/').'/api/trial/'.$id.'/format/default' : null;
    }

    private function firstStart(?CarbonInterface $updatedSince): CarbonImmutable
    {
        if ($updatedSince !== null) {
            return CarbonImmutable::parse($updatedSince->format('Y-m-d'), 'UTC')->startOfDay();
        }

        return CarbonImmutable::parse($this->fullImportFrom, 'UTC')->startOfDay();
    }
}
