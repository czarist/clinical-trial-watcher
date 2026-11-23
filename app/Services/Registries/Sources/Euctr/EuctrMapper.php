<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Euctr;

use App\Enums\StudyStatus;
use App\Exceptions\InvalidStudyPayload;
use App\Services\ClinicalTrials\Import\MappedStudy;
use App\Services\ClinicalTrials\Import\RawDocument;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\Registries\Identifiers\IdentifierParser;
use App\Services\Registries\Sources\MapsRegistryRecords;

/**
 * Maps one EU CTR trial: the text blocks of its download, one per member state (payload
 * `blocks`), grouped under its EudraCT number (`eudract`).
 *
 * The protocol (sections A to G) is the same sponsor protocol in every member state, so it is
 * read from the first block; the member states, their statuses and planned participants come
 * from every block. The sponsor's contact point (name, phone, fax, email) is never imported
 * and is removed from the kept raw text.
 */
final class EuctrMapper implements StudyPayloadMapper
{
    use MapsRegistryRecords;

    /**
     * Per-member-state statuses. "Ongoing" and "Restarted" say nothing about recruitment, and
     * "Not Authorised", "Prohibited by CA" and "GB - no longer in EU/EEA" have no equivalent:
     * they map to null.
     */
    private const array STATUSES = [
        'completed' => StudyStatus::Completed,
        'prematurely ended' => StudyStatus::Terminated,
        'temporarily halted' => StudyStatus::Suspended,
    ];

    private const array PHASES = ['E.7.1' => 'PHASE1', 'E.7.2' => 'PHASE2', 'E.7.3' => 'PHASE3', 'E.7.4' => 'PHASE4'];

    /** Lines of the sponsor's contact point removed from the raw text. */
    private const string PERSONAL_LINE = '/^B\.5\.(?:2|4|5|6)\b.*$/m';

    private const array COUNTRIES = ['UK' => 'United Kingdom'];

    public function payloadId(array $payload): ?string
    {
        $id = $payload['eudract'] ?? null;

        return is_string($id) && preg_match(EuctrSource::ID_PATTERN, $id) === 1 ? $id : null;
    }

    public function map(array $payload): MappedStudy
    {
        $id = $this->payloadId($payload) ?? throw new InvalidStudyPayload('The EU CTR record has no valid EudraCT number.');
        $blocks = array_values(array_filter(is_array($payload['blocks'] ?? null) ? $payload['blocks'] : [], 'is_string'));

        if ($blocks === []) {
            throw new InvalidStudyPayload("The EU CTR record {$id} has no protocol text.");
        }

        $states = array_map(fn (string $block): array => self::fields($block), $blocks);
        $protocol = $states[0];
        $statuses = array_map(fn (array $fields): ?string => $this->value($fields, 'Trial Status') ?? $this->value($fields, 'P. End of Trial Status'), $states);
        $status = $this->status($statuses);

        $dates = [
            'start_date' => null,
            'primary_completion_date' => null,
            'completion_date' => $this->earliest($states, 'P. Date of the global end of the trial'),
        ];

        $enrollment = $this->enrollment($states);

        $attributes = [
            'brief_title' => $this->short($this->value($protocol, 'A.3.1')) ?? $this->short($this->value($protocol, 'A.3')) ?? $id,
            'official_title' => $this->value($protocol, 'A.3'),
            'acronym' => $this->short($this->value($protocol, 'A.3.2')),
            'org_study_id' => $this->short($this->value($protocol, 'A.4.1') ?? $this->value($protocol, "Sponsor's Protocol Code Number")),
            'overall_status' => $status?->value,
            'status_verified_date' => null,
            ...$dates,
            'study_first_post_date' => $this->earliest($states, 'Date on which this record was first entered in the EudraCT database'),
            'last_update_post_date' => null,
            'has_expanded_access' => false,
            'brief_summary' => $this->value($protocol, 'E.2.1'),
            'detailed_description' => null,
            'study_type' => 'INTERVENTIONAL',
            'phase' => $this->phase($protocol),
            'enrollment_count' => $enrollment,
            'enrollment_type' => $enrollment !== null ? 'ESTIMATED' : null,
            'sponsor_name' => $this->short($this->value($protocol, 'B.1.1')),
            'sponsor_class' => match ($this->value($protocol, 'B.3.1 and B.3.2')) {
                'Commercial' => 'INDUSTRY',
                'Non-Commercial' => 'OTHER',
                default => null,
            },
            'responsible_party_type' => null,
            'eligibility_criteria' => $this->eligibility($protocol),
            'min_age' => null,
            'max_age' => null,
            'gender' => match ([$this->value($protocol, 'F.2.1'), $this->value($protocol, 'F.2.2')]) {
                ['Yes', 'Yes'] => 'ALL',
                ['Yes', 'No'] => 'FEMALE',
                ['No', 'Yes'] => 'MALE',
                default => null,
            },
            'is_fda_regulated_drug' => false,
            'is_fda_regulated_device' => false,
            'ipd_sharing' => null,
            'has_results' => false,
        ];

        $relations = $this->emptyRelations();
        $relations['secondaryIds'] = $this->secondaryIds($protocol);
        $relations['collaborators'] = $this->collaborators($protocol, $attributes['sponsor_name']);
        $relations['conditions'] = $this->rows('condition', [$this->value($protocol, 'E.1.1')]);
        $relations['keywords'] = $this->rows('keyword', [$this->value($protocol, 'E.1.1.2'), ...$this->values($protocol, 'E.1.2 Term')]);
        $relations['interventions'] = $this->interventions($protocol);
        $relations['locations'] = $this->locations($states, $statuses);
        $relations['outcomes'] = $this->outcomes($protocol);

        return new MappedStudy(
            source: EuctrSource::KEY,
            sourceId: $id,
            attributes: $attributes,
            relations: $relations,
            facts: $this->facts($status?->value, $relations['locations'], $dates, false),
            raw: new RawDocument(
                'txt',
                (string) preg_replace(self::PERSONAL_LINE, '', implode("\n\n", $blocks)),
                is_string($payload['url'] ?? null) ? $payload['url'] : null,
            ),
        );
    }

    /**
     * The "Code label: value" fields of one block, in order. A value runs over the following
     * lines until the next coded line, section heading or summary field.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function fields(string $block): array
    {
        $fields = [];
        $current = null;

        foreach (explode("\n", str_replace("\r\n", "\n", $block)) as $line) {
            $summary = preg_match("/^(EudraCT Number|Sponsor's Protocol Code Number|National Competent Authority|Clinical Trial Type|Trial Status|Date on which this record was first entered in the EudraCT database|Link):(.*)$/", $line, $match) === 1;

            if ($summary || preg_match('/^[A-P]\.(?:[0-9A-Z]+(?:\.[0-9]+)*)?[\s:]/', $line) === 1) {
                if (! $summary && ! str_contains($line, ':')) {
                    $current = null; // a section or group heading

                    continue;
                }

                [$key, $value] = $summary ? [$match[1], $match[2]] : explode(':', $line, 2);
                $fields[] = [trim((string) preg_replace('/\s+/', ' ', $key)), $value];
                $current = array_key_last($fields);

                continue;
            }

            if ($line === 'Summary' || $line === 'MedDRA Classification' || preg_match('/^Sponsor \d+$/', $line) === 1) {
                $current = null;

                continue;
            }

            if ($current !== null) {
                $fields[$current][1] .= "\n".$line;
            }
        }

        return array_map(fn (array $field): array => [$field[0], trim($field[1])], $fields);
    }

    /**
     * Values of the fields whose key is the given code or starts with the code and a space.
     *
     * @param  list<array{0: string, 1: string}>  $fields
     * @return list<string>
     */
    private function values(array $fields, string $code): array
    {
        $values = [];

        foreach ($fields as [$key, $value]) {
            if (($key === $code || str_starts_with($key, $code.' ')) && $value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $fields
     */
    private function value(array $fields, string $code): ?string
    {
        return $this->values($fields, $code)[0] ?? null;
    }

    /**
     * The trial's status from its member states: any state still running (ongoing, restarted)
     * leaves it unknown, then halted, completed and prematurely ended in that order.
     *
     * @param  list<string|null>  $statuses
     */
    private function status(array $statuses): ?StudyStatus
    {
        $known = array_map(fn (?string $status): string => strtolower(trim((string) $status)), array_filter($statuses));

        if ($known === [] || array_intersect($known, ['ongoing', 'restarted']) !== []) {
            return null;
        }

        foreach (['temporarily halted', 'completed', 'prematurely ended'] as $status) {
            if (in_array($status, $known, true)) {
                return self::STATUSES[$status];
            }
        }

        return null;
    }

    /**
     * @param  list<list<array{0: string, 1: string}>>  $states
     */
    private function earliest(array $states, string $code): ?string
    {
        $dates = array_filter(array_map(fn (array $fields): ?string => $this->date($this->value($fields, $code)), $states));
        sort($dates);

        return $dates[0] ?? null;
    }

    /**
     * Planned participants of the whole trial, else of the EEA, else the sum of the member states.
     *
     * @param  list<list<array{0: string, 1: string}>>  $states
     */
    private function enrollment(array $states): ?int
    {
        foreach (['F.4.2.2', 'F.4.2.1'] as $code) {
            $value = $this->value($states[0], $code);

            if ($value !== null && ctype_digit($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        $counts = array_filter(array_map(fn (array $fields): ?string => $this->value($fields, 'F.4.1'), $states), fn (?string $value): bool => $value !== null && ctype_digit($value));
        $total = array_sum(array_map('intval', $counts));

        return $total > 0 ? $total : null;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $protocol
     */
    private function phase(array $protocol): ?string
    {
        $phases = [];

        foreach (self::PHASES as $code => $phase) {
            if ($this->value($protocol, $code) === 'Yes') {
                $phases[] = $phase;
            }
        }

        return $phases === [] ? null : implode(', ', $phases);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $protocol
     */
    private function eligibility(array $protocol): ?string
    {
        $sections = array_filter(['Inclusion criteria' => $this->value($protocol, 'E.3'), 'Exclusion criteria' => $this->value($protocol, 'E.4')]);

        return $sections === [] ? null : implode("\n\n", array_map(
            fn (string $heading, string $text): string => "{$heading}:\n{$text}",
            array_keys($sections),
            $sections,
        ));
    }

    /**
     * ISRCTN (A.5.1), NCT (A.5.2), WHO UTN (A.5.3) and other identifiers (A.5.4) that parse as
     * a registry identifier. The sponsor's protocol code (A.4.1) is never used for linking.
     *
     * @param  list<array{0: string, 1: string}>  $protocol
     * @return list<array<string, string|null>>
     */
    private function secondaryIds(array $protocol): array
    {
        $parser = new IdentifierParser;
        $pairs = [];

        foreach (['A.5.1' => 'ISRCTN', 'A.5.2' => 'NCT', 'A.5.3' => 'UTN', 'A.5.4' => null] as $code => $type) {
            foreach ($this->values($protocol, $code) as $value) {
                if ($parser->parse($value) !== null) {
                    $pairs[] = [$value, $type];
                }
            }
        }

        return $this->secondaryIdRows($pairs);
    }

    /**
     * Organisations providing support (B.4.1) other than the sponsor.
     *
     * @param  list<array{0: string, 1: string}>  $protocol
     * @return list<array<string, mixed>>
     */
    private function collaborators(array $protocol, ?string $sponsor): array
    {
        $rows = [];

        foreach ($this->values($protocol, 'B.4.1') as $name) {
            $name = $this->short($name);

            if ($name !== null && $name !== $sponsor) {
                $rows[$name] = ['name' => $name, 'class' => null];
            }
        }

        return array_values($rows);
    }

    /**
     * Investigational medicinal products: each "D.IMP: n" starts one.
     *
     * @param  list<array{0: string, 1: string}>  $protocol
     * @return list<array<string, mixed>>
     */
    private function interventions(array $protocol): array
    {
        $products = [];
        $current = null;

        foreach ($protocol as [$key, $value]) {
            if ($key === 'D.IMP') {
                $products[] = ['trade' => null, 'name' => null, 'inn' => []];
                $current = array_key_last($products);
            } elseif ($current !== null && $value !== '') {
                match (true) {
                    str_starts_with($key, 'D.2.1.1.1 ') => $products[$current]['trade'] ??= $value,
                    str_starts_with($key, 'D.3.1 ') => $products[$current]['name'] ??= $value,
                    str_starts_with($key, 'D.3.8 ') => $products[$current]['inn'][] = $value,
                    default => null,
                };
            }
        }

        $rows = [];

        foreach ($products as $product) {
            $name = $this->text($product['trade'] ?? $product['name'] ?? $product['inn'][0] ?? null);

            if ($name !== null) {
                $inn = array_values(array_diff(array_unique($product['inn']), [$name]));
                $rows[$name] = ['type' => 'DRUG', 'name' => $name, 'description' => $inn === [] ? null : 'INN: '.implode(', ', $inn)];
            }
        }

        return array_values($rows);
    }

    /**
     * One row per member state ("UK - MHRA" gives "United Kingdom") with its protocol status.
     *
     * @param  list<list<array{0: string, 1: string}>>  $states
     * @param  list<string|null>  $statuses
     * @return list<array<string, mixed>>
     */
    private function locations(array $states, array $statuses): array
    {
        $rows = [];

        foreach ($states as $index => $fields) {
            $authority = $this->value($fields, 'A.1') ?? $this->value($fields, 'National Competent Authority');
            $country = $authority !== null ? trim(explode(' - ', $authority, 2)[0]) : '';

            if ($country === '') {
                continue;
            }

            $country = self::COUNTRIES[$country] ?? $country;
            $rows[$country] = [
                'facility' => null,
                'city' => null,
                'state' => null,
                'country' => $this->short($country),
                'status' => $this->short($statuses[$index] ?? null),
                'zip' => null,
                'latitude' => null,
                'longitude' => null,
            ];
        }

        return array_values($rows);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $protocol
     * @return list<array<string, mixed>>
     */
    private function outcomes(array $protocol): array
    {
        $rows = [];

        foreach (['PRIMARY' => 'E.5.1', 'SECONDARY' => 'E.5.2'] as $type => $code) {
            $measure = $this->value($protocol, $code);

            if ($measure !== null) {
                $rows[] = ['type' => $type, 'measure' => $measure, 'description' => null, 'time_frame' => $this->value($protocol, $code.'.1')];
            }
        }

        return $rows;
    }

    /**
     * @param  list<string|null>  $values
     * @return list<array<string, string>>
     */
    private function rows(string $column, array $values): array
    {
        $rows = [];

        foreach ($values as $value) {
            $value = $this->short($value);

            if ($value !== null) {
                $rows[$value] = [$column => $value];
            }
        }

        return array_values($rows);
    }
}
