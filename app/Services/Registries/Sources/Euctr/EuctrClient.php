<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Euctr;

use App\Exceptions\RegistryRequestFailed;
use App\Services\ClinicalTrials\Import\StudyPage;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\RegistrySource;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Reads the EU Clinical Trials Register through the plain-text download of each search
 * results page (`rest/download/full?query&dateFrom&page&mode=current_page`).
 *
 * A results page holds `trialsPerPage` trials (20 on the register) and the download repeats
 * a trial's protocol once per member state; the blocks are grouped by EudraCT number, so one
 * payload is one trial. The register gives no page count: a page with fewer trials than a
 * full page is the last one, and a page that starts with the same trial as the previous one
 * (the register repeating its last page) ends the walk. The page token is the next page
 * number and the first EudraCT number of the page just read.
 *
 * Incremental runs filter on `dateFrom`, the date a trial was first entered in EudraCT: new
 * trials are found, but changes to older trials (status, end of trial) are seen only by a
 * full import. The register has no per-record last-update date.
 */
final class EuctrClient implements StudyRegistryClient
{
    public function __construct(
        private readonly RegistryFetcher $fetcher,
        private readonly RegistrySource $source,
        private readonly string $baseUrl = 'https://www.clinicaltrialsregister.eu/ctr-search',
        private readonly string $query = '',
        private readonly int $trialsPerPage = 20,
    ) {}

    public function fetchPage(int $pageSize, ?string $pageToken = null, ?CarbonInterface $updatedSince = null): StudyPage
    {
        [$page, $previousFirst] = $pageToken !== null && $pageToken !== '' ? self::decodeToken($pageToken) : [1, null];

        $document = $this->fetcher->get($this->source, rtrim($this->baseUrl, '/').'/rest/download/full', [
            'query' => $this->query,
            'dateFrom' => $updatedSince?->format('Y-m-d'),
            'page' => $page,
            'mode' => 'current_page',
        ]);

        $trials = self::splitTrials($document->body);
        $first = array_key_first($trials);

        if ($first === null || ($previousFirst !== null && $first === $previousFirst)) {
            return new StudyPage([], null);
        }

        $studies = [];

        foreach ($trials as $eudract => $blocks) {
            $studies[] = ['eudract' => $eudract, 'blocks' => $blocks, 'url' => $document->url];
        }

        return new StudyPage($studies, count($trials) >= max(1, $this->trialsPerPage) ? self::encodeToken($page + 1, $first) : null);
    }

    /**
     * The blocks of a download grouped by EudraCT number, in the order of the file.
     *
     * @return array<string, list<string>>
     *
     * @throws RegistryRequestFailed when the body is not a register text download
     */
    public static function splitTrials(string $body): array
    {
        $body = str_replace("\r\n", "\n", (string) preg_replace('/^\xEF\xBB\xBF/', '', $body));
        $head = ltrim(substr($body, 0, 2000));

        if ($head === '' || stripos($head, '<html') !== false || stripos($head, '<!doctype') !== false
            || (! str_starts_with($head, 'This file contains') && ! str_contains($head, 'EudraCT Number:'))) {
            throw RegistryRequestFailed::invalidPayload();
        }

        $trials = [];
        // The export may use Windows line endings; the split below expects "\n".
        $body = str_replace("\r\n", "\n", $body);

        foreach (preg_split('/\n(?=Summary\nEudraCT Number:)/', $body) ?: [] as $chunk) {
            if (preg_match('/^Summary\nEudraCT Number:\s*(\S+)/', $chunk, $match) !== 1) {
                continue; // the file's introduction
            }

            if (preg_match(EuctrSource::ID_PATTERN, $match[1]) !== 1) {
                throw new RegistryRequestFailed("The register download holds an invalid EudraCT number [{$match[1]}]; the format may have changed.");
            }

            $trials[$match[1]][] = rtrim($chunk);
        }

        return $trials;
    }

    /**
     * @return array{0: int, 1: string} the page number and the first EudraCT number of the previous page
     */
    public static function decodeToken(string $token): array
    {
        if (preg_match('/^(\d+)\|((?:19|20)\d{2}-\d{6}-\d{2})$/', $token, $match) !== 1 || (int) $match[1] < 2) {
            throw new InvalidArgumentException("Invalid EU CTR page token [{$token}].");
        }

        return [(int) $match[1], $match[2]];
    }

    public static function encodeToken(int $page, string $previousFirst): string
    {
        return $page.'|'.$previousFirst;
    }
}
