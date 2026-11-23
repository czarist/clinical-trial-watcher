<?php

declare(strict_types=1);

namespace App\Services\ClinicalTrials\Search;

use App\Enums\StudyPhase;
use App\Enums\StudyStatus;
use App\Enums\StudyType;
use Illuminate\Validation\Rule;

/**
 * Filters for searching studies. Used by the search endpoint and stored by alerts.
 */
final readonly class TrialSearchCriteria
{
    /**
     * @param  list<string>  $statuses
     * @param  list<string>  $phases
     * @param  list<string>  $studyTypes
     * @param  list<string>  $sources  registry keys; a study matches when any of its registrations comes from one
     */
    public function __construct(
        public ?string $q = null,
        public ?string $condition = null,
        public ?string $keyword = null,
        public ?string $meshTerm = null,
        public ?string $sponsor = null,
        public ?string $country = null,
        public ?string $city = null,
        public array $statuses = [],
        public array $phases = [],
        public array $studyTypes = [],
        public ?bool $hasResults = null,
        public ?string $startDateFrom = null,
        public ?string $startDateTo = null,
        public ?string $firstPostedFrom = null,
        public ?string $firstPostedTo = null,
        public ?string $lastUpdateFrom = null,
        public ?string $lastUpdateTo = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?float $radiusKm = null,
        public array $sources = [],
    ) {}

    /**
     * Validation rules, optionally nested under a prefix such as "criteria.".
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(string $prefix = ''): array
    {
        $text = ['nullable', 'string', 'max:255'];
        $date = ['nullable', 'date_format:Y-m-d'];

        return [
            "{$prefix}q" => $text,
            "{$prefix}condition" => $text,
            "{$prefix}keyword" => $text,
            "{$prefix}mesh_term" => $text,
            "{$prefix}sponsor" => $text,
            "{$prefix}country" => $text,
            "{$prefix}city" => $text,
            "{$prefix}status" => ['nullable', 'array', 'max:14'],
            "{$prefix}status.*" => [Rule::enum(StudyStatus::class)],
            "{$prefix}phase" => ['nullable', 'array', 'max:6'],
            "{$prefix}phase.*" => [Rule::enum(StudyPhase::class)],
            "{$prefix}study_type" => ['nullable', 'array', 'max:3'],
            "{$prefix}study_type.*" => [Rule::enum(StudyType::class)],
            "{$prefix}has_results" => ['nullable', 'boolean'],
            "{$prefix}start_date_from" => $date,
            "{$prefix}start_date_to" => [...$date, "after_or_equal:{$prefix}start_date_from"],
            "{$prefix}first_posted_from" => $date,
            "{$prefix}first_posted_to" => [...$date, "after_or_equal:{$prefix}first_posted_from"],
            "{$prefix}last_update_from" => $date,
            "{$prefix}last_update_to" => [...$date, "after_or_equal:{$prefix}last_update_from"],
            "{$prefix}latitude" => ['nullable', 'numeric', 'between:-90,90', "required_with:{$prefix}longitude,{$prefix}radius_km"],
            "{$prefix}longitude" => ['nullable', 'numeric', 'between:-180,180', "required_with:{$prefix}latitude,{$prefix}radius_km"],
            "{$prefix}radius_km" => ['nullable', 'numeric', 'between:1,2000', "required_with:{$prefix}latitude,{$prefix}longitude"],
            "{$prefix}source" => ['nullable', 'array', 'max:50'],
            "{$prefix}source.*" => [Rule::in(array_keys((array) config('registries.sources')))],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  validated input using the snake_case keys of rules()
     */
    public static function fromArray(array $data): self
    {
        $string = static fn (string $key): ?string => isset($data[$key]) && $data[$key] !== '' ? (string) $data[$key] : null;
        $float = static fn (string $key): ?float => isset($data[$key]) && $data[$key] !== '' ? (float) $data[$key] : null;
        $list = static fn (string $key): array => array_values(array_unique(array_map('strval', (array) ($data[$key] ?? []))));

        return new self(
            q: $string('q'),
            condition: $string('condition'),
            keyword: $string('keyword'),
            meshTerm: $string('mesh_term'),
            sponsor: $string('sponsor'),
            country: $string('country'),
            city: $string('city'),
            statuses: $list('status'),
            phases: $list('phase'),
            studyTypes: $list('study_type'),
            hasResults: isset($data['has_results']) ? filter_var($data['has_results'], FILTER_VALIDATE_BOOL) : null,
            startDateFrom: $string('start_date_from'),
            startDateTo: $string('start_date_to'),
            firstPostedFrom: $string('first_posted_from'),
            firstPostedTo: $string('first_posted_to'),
            lastUpdateFrom: $string('last_update_from'),
            lastUpdateTo: $string('last_update_to'),
            latitude: $float('latitude'),
            longitude: $float('longitude'),
            radiusKm: $float('radius_km'),
            sources: $list('source'),
        );
    }

    /**
     * The criteria with snake_case keys, omitting empty values.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'q' => $this->q,
            'condition' => $this->condition,
            'keyword' => $this->keyword,
            'mesh_term' => $this->meshTerm,
            'sponsor' => $this->sponsor,
            'country' => $this->country,
            'city' => $this->city,
            'status' => $this->statuses,
            'phase' => $this->phases,
            'study_type' => $this->studyTypes,
            'has_results' => $this->hasResults,
            'start_date_from' => $this->startDateFrom,
            'start_date_to' => $this->startDateTo,
            'first_posted_from' => $this->firstPostedFrom,
            'first_posted_to' => $this->firstPostedTo,
            'last_update_from' => $this->lastUpdateFrom,
            'last_update_to' => $this->lastUpdateTo,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'radius_km' => $this->radiusKm,
            'source' => $this->sources,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    public function isEmpty(): bool
    {
        return $this->toArray() === [];
    }

    public function hasDistance(): bool
    {
        return $this->latitude !== null && $this->longitude !== null && $this->radiusKm !== null;
    }
}
