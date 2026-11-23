<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources;

use App\Models\ClinicalTrials\ClinicalTrial;
use App\Services\ClinicalTrials\Changes\TrackedFacts;
use App\Services\ClinicalTrials\Import\RegistryDate;
use Carbon\CarbonImmutable;

/**
 * Helpers shared by the mappers of registries other than ClinicalTrials.gov, so every record
 * reaches the common study model the same way.
 */
trait MapsRegistryRecords
{
    /**
     * Every synced relation, empty: a mapper fills the ones its registry publishes, and the
     * synchronizer replaces each list, removing rows the registry no longer lists.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    protected function emptyRelations(): array
    {
        return array_fill_keys(ClinicalTrial::SYNCED_RELATIONS, []);
    }

    /** Trimmed text cut to a column's length, or null when empty. */
    protected function short(mixed $value, int $length = 255): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return $value === '' ? null : mb_substr($value, 0, $length);
    }

    /** Trimmed long text, or null when empty. */
    protected function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * A registry date as YYYY-MM-DD. Accepts ISO dates and date-times, partial dates
     * (2024, 2024-06), the given day-first or other formats, and Excel serial numbers.
     */
    protected function date(mixed $value, string ...$formats): ?string
    {
        if (is_int($value) || (is_string($value) && preg_match('/^\d{5}(\.\d+)?$/', $value) === 1)) {
            // Excel serial date (days since 1899-12-30).
            return CarbonImmutable::create(1899, 12, 30)->addDays((int) $value)->toDateString();
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ]/', $value, $match) === 1) {
            return $match[1];
        }

        foreach ($formats as $format) {
            $date = CarbonImmutable::createFromFormat('!'.$format, $value);

            if ($date !== null && $date->format($format) === $value) {
                return $date->toDateString();
            }
        }

        return RegistryDate::normalize($value);
    }

    /**
     * Rows of the secondaryIds relation; IdentifierParser reads them to link registrations.
     *
     * @param  iterable<array{0: string|null, 1?: string|null}>  $ids  pairs of identifier and type
     * @return list<array<string, string|null>>
     */
    protected function secondaryIdRows(iterable $ids): array
    {
        $rows = [];

        foreach ($ids as $pair) {
            $id = $this->short($pair[0] ?? null);

            if ($id !== null) {
                $rows[$id] = ['secondary_id' => $id, 'type' => $this->short($pair[1] ?? null), 'domain' => null, 'link' => null];
            }
        }

        return array_values($rows);
    }

    /**
     * Tracked facts compared between imports to describe changes.
     *
     * @param  list<array<string, mixed>>  $locations  rows of the locations relation
     * @param  array<string, string|null>  $dates  start_date, primary_completion_date, completion_date
     */
    protected function facts(?string $status, array $locations, array $dates, bool $hasResults = false): TrackedFacts
    {
        $sites = [];

        foreach ($locations as $location) {
            $site = [
                'facility' => $this->short($location['facility'] ?? null),
                'city' => $this->short($location['city'] ?? null),
                'state' => $this->short($location['state'] ?? null),
                'country' => $this->short($location['country'] ?? null),
                'status' => $this->short($location['status'] ?? null),
            ];
            $sites[TrackedFacts::siteKey($site)] = $site;
        }

        ksort($sites, SORT_STRING);

        $tracked = [];

        foreach (array_keys(TrackedFacts::DATES) as $name) {
            $tracked[$name] = isset($dates[$name]) ? ['date' => $dates[$name], 'type' => null] : null;
        }

        return new TrackedFacts($status, $sites, $tracked, $hasResults);
    }
}
