<?php

declare(strict_types=1);

namespace App\Services\ClinicalTrials\Changes;

use Illuminate\Support\Arr;

/**
 * The facts of a study compared between imports to describe what changed.
 *
 * Dates keep the registry's original text ("2025", "2025-06" or "2025-06-15") so a digest
 * never presents a month-only date as an exact day. Sites are keyed by facility and place
 * and sorted, so a change in the order the registry lists them is not a change.
 */
final readonly class TrackedFacts
{
    /** Key dates tracked, keyed by name, with their path in the statusModule. */
    public const array DATES = [
        'start_date' => 'startDateStruct',
        'primary_completion_date' => 'primaryCompletionDateStruct',
        'completion_date' => 'completionDateStruct',
    ];

    /**
     * @param  array<string, array{facility: ?string, city: ?string, state: ?string, country: ?string, status: ?string}>  $sites  keyed by site key, sorted
     * @param  array<string, array{date: string, type: ?string}|null>  $dates  keyed by self::DATES names
     */
    public function __construct(
        public ?string $overallStatus,
        public array $sites,
        public array $dates,
        public bool $hasResults,
    ) {}

    /**
     * @param  array<string, mixed>  $study  one study of the API v2 response
     */
    public static function fromStudy(array $study): self
    {
        $protocol = (array) ($study['protocolSection'] ?? []);
        $sites = [];

        foreach ((array) Arr::get($protocol, 'contactsLocationsModule.locations', []) as $location) {
            if (! is_array($location)) {
                continue;
            }

            $site = [
                'facility' => self::text($location['facility'] ?? null),
                'city' => self::text($location['city'] ?? null),
                'state' => self::text($location['state'] ?? null),
                'country' => self::text($location['country'] ?? null),
                'status' => self::text($location['status'] ?? null),
            ];
            $sites[self::siteKey($site)] = $site;
        }

        ksort($sites, SORT_STRING);

        $dates = [];

        foreach (self::DATES as $name => $struct) {
            $date = Arr::get($protocol, "statusModule.{$struct}.date");
            $dates[$name] = is_string($date) && $date !== ''
                ? ['date' => $date, 'type' => self::text(Arr::get($protocol, "statusModule.{$struct}.type"))]
                : null;
        }

        return new self(
            overallStatus: self::text(Arr::get($protocol, 'statusModule.overallStatus')),
            sites: $sites,
            dates: $dates,
            hasResults: (bool) ($study['hasResults'] ?? false),
        );
    }

    /**
     * @param  array<string, mixed>  $data  as stored by toArray()
     */
    public static function fromArray(array $data): self
    {
        return new self(
            overallStatus: $data['overall_status'] ?? null,
            sites: (array) ($data['sites'] ?? []),
            dates: (array) ($data['dates'] ?? []),
            hasResults: (bool) ($data['has_results'] ?? false),
        );
    }

    /**
     * @return array{overall_status: ?string, sites: array<string, array<string, ?string>>, dates: array<string, mixed>, has_results: bool}
     */
    public function toArray(): array
    {
        return [
            'overall_status' => $this->overallStatus,
            'sites' => $this->sites,
            'dates' => $this->dates,
            'has_results' => $this->hasResults,
        ];
    }

    public function hash(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_THROW_ON_ERROR));
    }

    /**
     * Identity of a site: facility and place, case and spacing ignored. The status is not
     * part of it, so a site whose status changes is an update, not a removal and an addition.
     *
     * @param  array{facility: ?string, city: ?string, state: ?string, country: ?string}  $site
     */
    public static function siteKey(array $site): string
    {
        return implode('|', array_map(
            static fn (?string $part): string => mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $part)) ?? ''),
            [$site['country'], $site['state'], $site['city'], $site['facility']],
        ));
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
