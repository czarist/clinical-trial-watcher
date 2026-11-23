<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Reec;

use App\Exceptions\RegistryRequestFailed;
use App\Services\ClinicalTrials\Import\StudyPage;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\RegistrySource;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Reads REec studies through the documented REST service.
 *
 * The service has no paging and no reliable "modified since" query (`getestudios/{date}`
 * returned nothing for recent dates), so studies are listed by registration date
 * (`estudios?fechadesde&fechahasta`) in windows of `windowDays`, and each listed study is
 * read in full from `json/detalle/{id}`; its status comes from the search endpoint
 * (`busquedaestudios`). A window holding more studies than a page is split by an offset. The
 * page token is the window's first day and the offset.
 *
 * Incremental runs are weak: a registration-date window cannot see changes to older studies,
 * so an incremental run starts `lookbackDays` before the given date and re-reads every study
 * registered since then. Older studies are refreshed only by a full import.
 */
final class ReecClient implements StudyRegistryClient
{
    /** Empty windows skipped within one page before returning an empty page. */
    private const int MAX_EMPTY_WINDOWS = 60;

    public function __construct(
        private readonly RegistryFetcher $fetcher,
        private readonly RegistrySource $source,
        private readonly string $baseUrl = 'https://reec.aemps.es/reec-services',
        private readonly string $fullImportFrom = '2013-01-01',
        private readonly int $windowDays = 14,
        private readonly int $lookbackDays = 90,
    ) {}

    public function fetchPage(int $pageSize, ?string $pageToken = null, ?CarbonInterface $updatedSince = null): StudyPage
    {
        $today = CarbonImmutable::today();
        $window = max(1, $this->windowDays);
        $limit = max(1, $pageSize);

        [$start, $offset] = $pageToken !== null && $pageToken !== ''
            ? self::decodeToken($pageToken)
            : [$this->firstDay($updatedSince), 0];

        for ($skipped = 0; $start->lessThanOrEqualTo($today); $skipped++) {
            $end = $start->addDays($window - 1)->min($today);
            $ids = self::parseListing($this->fetcher->get($this->source, $this->url('estudios'), [
                'xml' => 'false',
                'fechadesde' => $start->format('d/m/Y'),
                'fechahasta' => $end->format('d/m/Y'),
            ], ['Accept' => 'application/json'])->body);

            $nextWindow = $end->greaterThanOrEqualTo($today) ? null : self::encodeToken($end->addDay(), 0);

            if ($ids === [] || $offset >= count($ids)) {
                if ($nextWindow === null || $skipped + 1 >= self::MAX_EMPTY_WINDOWS) {
                    return new StudyPage([], $nextWindow);
                }

                [$start, $offset] = [$end->addDay(), 0];

                continue;
            }

            $studies = array_map(fn (string $id): array => $this->study($id), array_slice($ids, $offset, $limit));
            $next = $offset + $limit < count($ids) ? self::encodeToken($start, $offset + $limit) : $nextWindow;

            return new StudyPage($studies, $next);
        }

        return new StudyPage([], null);
    }

    /**
     * Identifiers of the studies of a listing, in order and without repeats.
     *
     * @return list<string>
     *
     * @throws RegistryRequestFailed when the body is not a REec study list
     */
    public static function parseListing(string $body): array
    {
        $decoded = json_decode($body, true);

        if (! is_array($decoded) || ! array_key_exists('estudio', $decoded)) {
            throw RegistryRequestFailed::invalidPayload();
        }

        $ids = [];

        foreach (self::items($decoded['estudio']) as $study) {
            $id = is_string($study['identificador'] ?? null) ? trim($study['identificador']) : '';

            if ($id === '') {
                throw new RegistryRequestFailed('A REec listed study has no identifier; the response format may have changed.');
            }

            $ids[$id] = $id;
        }

        return array_values($ids);
    }

    /**
     * The row of a search response describing the given study, or null.
     *
     * @return array<string, mixed>|null
     *
     * @throws RegistryRequestFailed when the body is not a REec search result
     */
    public static function parseSearch(string $body, string $id): ?array
    {
        $decoded = json_decode($body, true);

        if (! is_array($decoded) || ! array_key_exists('estudio', $decoded)) {
            throw RegistryRequestFailed::invalidPayload();
        }

        foreach (self::items($decoded['estudio']) as $row) {
            if (($row['identificador'] ?? null) === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * A list of objects, also when the service returns a single object instead of a list.
     *
     * @return list<array<string, mixed>>
     */
    public static function items(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        if (! array_is_list($value)) {
            $value = [$value];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /**
     * @return array{0: CarbonImmutable, 1: int} the window's first day and the offset in it
     */
    public static function decodeToken(string $token): array
    {
        $parts = explode('|', $token);
        $start = count($parts) === 2 ? CarbonImmutable::createFromFormat('!Y-m-d', $parts[0]) : null;

        if (! $start instanceof CarbonImmutable || $start->format('Y-m-d') !== $parts[0] || ! ctype_digit($parts[1])) {
            throw new InvalidArgumentException("Invalid REec page token [{$token}].");
        }

        return [$start, (int) $parts[1]];
    }

    public static function encodeToken(CarbonInterface $start, int $offset): string
    {
        return $start->format('Y-m-d').'|'.$offset;
    }

    /**
     * The detail of a study, with its search row (status, results flag) under `busqueda`.
     *
     * @return array<string, mixed>
     */
    private function study(string $id): array
    {
        $detail = json_decode($this->fetcher->get($this->source, $this->url('json/detalle/'.rawurlencode($id)), [], ['Accept' => 'application/json'])->body, true);

        if (! is_array($detail) || ! is_string($detail['identificador'] ?? null) || trim($detail['identificador']) !== $id) {
            throw new RegistryRequestFailed("The REec detail of {$id} is not a study document; the response format may have changed.");
        }

        $search = ReecMapper::isTrialNumber($id)
            ? self::parseSearch($this->fetcher->get($this->source, $this->url('busquedaestudios'), ['xml' => '0', 'eudract' => $id], ['Accept' => 'application/json'])->body, $id)
            : null;

        return $detail + ['busqueda' => $search];
    }

    private function firstDay(?CarbonInterface $updatedSince): CarbonImmutable
    {
        return $updatedSince !== null
            ? CarbonImmutable::parse($updatedSince->format('Y-m-d'))->subDays(max(0, $this->lookbackDays))
            : CarbonImmutable::parse($this->fullImportFrom)->startOfDay();
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/'.$path;
    }
}
