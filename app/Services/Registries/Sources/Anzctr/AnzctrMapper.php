<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Anzctr;

use App\Enums\StudyPhase;
use App\Enums\StudyStatus;
use App\Enums\StudyType;
use App\Exceptions\InvalidStudyPayload;
use App\Services\ClinicalTrials\Import\MappedStudy;
use App\Services\ClinicalTrials\Import\RawDocument;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\Registries\Parsing\HtmlDocument;
use App\Services\Registries\Sources\MapsRegistryRecords;
use Carbon\CarbonImmutable;
use DOMElement;

/**
 * Maps an ANZCTR trial page to the common study model. Every value sits in an element whose id
 * names the registration field (ctl00_body_CX...); repeated fields (identifiers, conditions,
 * outcomes, sponsors) use ctl00_body_repeater_TX{group}_ctl{n}_CX{field}.
 *
 * Related trial records are other registrations and are kept in raw_json, never used to link
 * duplicates. Contacts (names, addresses, phones, emails) are never mapped and are removed
 * from the stored page.
 */
final class AnzctrMapper implements StudyPayloadMapper
{
    use MapsRegistryRecords;

    /** ANZCTR recruitment statuses; "Closed: follow-up continuing" goes on without enrolling. */
    private const array STATUSES = [
        'not yet recruiting' => StudyStatus::NotYetRecruiting,
        'recruiting' => StudyStatus::Recruiting,
        'active, not recruiting' => StudyStatus::ActiveNotRecruiting,
        'closed: follow-up continuing' => StudyStatus::ActiveNotRecruiting,
        'closed: follow-up complete' => StudyStatus::Completed,
        'completed' => StudyStatus::Completed,
        'stopped early' => StudyStatus::Terminated,
        'suspended' => StudyStatus::Suspended,
        'withdrawn' => StudyStatus::Withdrawn,
    ];

    /** Values the registry uses for "no identifier". */
    private const array NO_VALUE = ['nil known', 'nil', 'none', 'n/a', 'na', 'not applicable'];

    public function payloadId(array $payload): ?string
    {
        return is_string($payload['id'] ?? null) ? $payload['id'] : null;
    }

    public function map(array $payload): MappedStudy
    {
        $html = $payload['html'] ?? null;

        if (! is_string($html) || trim($html) === '') {
            throw new InvalidStudyPayload('The ANZCTR payload has no trial page.');
        }

        [$fields, $repeaters] = $this->fields(new HtmlDocument((string) preg_replace('#<br\s*/?>#i', "\n", $html)));
        $id = AnzctrSource::canonicalId($fields['CXACTRNUMBER'] ?? null);

        if ($id === null) {
            throw new InvalidStudyPayload('The ANZCTR trial page has no ACTRN number.');
        }

        $title = $this->short($fields['CXSTUDYTITLE'] ?? null) ?? $this->short($fields['CXSCIENTIFICTITLE'] ?? null);

        if ($title === null) {
            throw new InvalidStudyPayload("The ANZCTR trial {$id} has no title.");
        }

        $status = $this->status($fields['CXRECRUITMENTSTATUS'] ?? null);
        $startDate = self::dayFirstDate($fields['CXACTUALSTARTDATE'] ?? null) ?? self::dayFirstDate($fields['CXANTICIPATEDSTARTDATE'] ?? null);
        $completionDate = self::dayFirstDate($fields['CXACTUALLASTVISITDATE'] ?? null) ?? self::dayFirstDate($fields['CXANTICIPATEDLASTVISITDATE'] ?? null);
        $actualSize = $this->count($fields['CXACTUALSAMPLESIZE'] ?? null);
        $targetSize = $this->count($fields['CXSAMPLESIZE'] ?? null);
        $locations = $this->locations($fields);
        $url = is_string($payload['url'] ?? null) ? $payload['url'] : AnzctrSource::trialUrl($id);

        return new MappedStudy(
            source: AnzctrSource::KEY,
            sourceId: $id,
            attributes: [
                'brief_title' => $title,
                'official_title' => $this->short($fields['CXSCIENTIFICTITLE'] ?? null),
                'acronym' => $this->short($fields['CXTRIALACRONYM'] ?? null),
                'org_study_id' => null,
                'overall_status' => $status,
                'status_verified_date' => null,
                'start_date' => $startDate,
                'primary_completion_date' => null,
                'completion_date' => $completionDate,
                'study_first_post_date' => self::dayFirstDate($fields['CXAPPROVALDATE'] ?? null),
                'last_update_post_date' => self::dayFirstDate($fields['CXUPDATEDATE'] ?? null),
                'brief_summary' => $this->text($fields['CXSUMMARY'] ?? null),
                'detailed_description' => null,
                'study_type' => match (strtolower((string) ($fields['CXSTUDYTYPE'] ?? ''))) {
                    'interventional' => StudyType::Interventional->value,
                    'observational' => StudyType::Observational->value,
                    default => null,
                },
                'phase' => $this->phase($fields['CXPHASE'] ?? null),
                'enrollment_count' => $actualSize ?? $targetSize,
                'enrollment_type' => $actualSize !== null ? 'ACTUAL' : ($targetSize !== null ? 'ESTIMATED' : null),
                'sponsor_name' => $this->short($fields['CXPRIMARYSPONSORNAME'] ?? null),
                'sponsor_class' => null,
                'eligibility_criteria' => $this->eligibility($fields),
                'min_age' => $this->age($fields['CXINCLUSIVEMINAGE'] ?? null, $fields['CXINCLUSIVEMINAGETYPE'] ?? null),
                'max_age' => $this->age($fields['CXINCLUSIVEMAXAGE'] ?? null, $fields['CXINCLUSIVEMAXAGETYPE'] ?? null),
                'gender' => $this->gender($fields['CXINCLUSIVEGENDER'] ?? null),
                'has_results' => false,
                'raw_json' => [
                    'related_trials' => $this->short($fields['CXLINKEDSTUDY'] ?? null),
                    'ethics_status' => $this->short($fields['CXETHICSREVIEW'] ?? $fields['CXETHICSREVIEWTOP'] ?? null),
                    'date_submitted' => self::dayFirstDate($fields['CXSUBMITDATE'] ?? null),
                    'healthy_volunteers' => $this->short($fields['CXHEALTHYVOLUNTEER'] ?? null),
                    'recruitment_states' => $this->short($fields['CXRECRUITMENTSTATE'] ?? null),
                ],
            ],
            relations: [
                ...$this->emptyRelations(),
                'secondaryIds' => $this->secondaryIds($fields, $repeaters),
                'collaborators' => $this->collaborators($repeaters),
                'conditions' => $this->rows('condition', $this->column($repeaters, 'HEALTHCONDITION', 'CXHEALTHCONDITION')),
                'keywords' => $this->rows('keyword', [
                    ...$this->column($repeaters, 'CONDITIONCODE', 'CXCONDITIONCODE1'),
                    ...$this->column($repeaters, 'CONDITIONCODE', 'CXCONDITIONCODE2'),
                ]),
                'locations' => $locations,
                'interventions' => $this->interventions($fields, $repeaters),
                'outcomes' => [...$this->outcomes($repeaters, 'PRIMARYOUTCOME', 'PRIMARY'), ...$this->outcomes($repeaters, 'SECONDARYOUTCOME', 'SECONDARY')],
            ],
            facts: $this->facts($status, $locations, ['start_date' => $startDate, 'completion_date' => $completionDate]),
            raw: new RawDocument('html', self::withoutPersonalData($html), $url),
        );
    }

    /** A date shown as d/mm/yyyy (day first), as YYYY-MM-DD. */
    public static function dayFirstDate(?string $value): ?string
    {
        if ($value === null || preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', trim($value), $match) !== 1
            || ! checkdate((int) $match[2], (int) $match[1], (int) $match[3])) {
            return null;
        }

        return CarbonImmutable::create((int) $match[3], (int) $match[2], (int) $match[1])?->toDateString();
    }

    /**
     * The page without contact details (names, addresses, phones, faxes, emails) and without
     * obfuscated or plain email addresses, so personal data is never stored.
     */
    public static function withoutPersonalData(string $html): string
    {
        $html = (string) preg_replace(
            '#(<span\b[^>]*id="ctl00_body_repeater_TXCONTACT\d+_ctl\d+_CX(?:NAME|ADDRESS|PHONE|FAX|EMAIL)"[^>]*>).*?(</span>)#si',
            '$1Redacted$2',
            $html,
        );
        $html = (string) preg_replace('#data-cfemail="[^"]*"#i', 'data-cfemail=""', $html);
        $html = (string) preg_replace('#mailto:[^"\'\s>]+#i', 'mailto:redacted', $html);

        return (string) preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', 'redacted@example.invalid', $html);
    }

    /**
     * Single fields keyed by field name (CXSTUDYTITLE), and repeated fields keyed by group and
     * row (HEALTHCONDITION => [0 => [CXHEALTHCONDITION => ...]]). Contacts are left out.
     *
     * @return array{0: array<string, string>, 1: array<string, array<int, array<string, string>>>}
     */
    private function fields(HtmlDocument $page): array
    {
        $fields = [];
        $repeaters = [];

        foreach ($page->nodes("//*[starts-with(@id, 'ctl00_body_')]") as $node) {
            $elementId = $node instanceof DOMElement ? $node->getAttribute('id') : '';
            $value = $this->multiline($node->textContent);

            if ($value === null) {
                continue;
            }

            if (preg_match('/^ctl00_body_repeater_TX(\w+?)_ctl(\d+)_(CX[A-Z0-9]+)$/', $elementId, $match) === 1) {
                if (! str_starts_with($match[1], 'CONTACT')) {
                    $repeaters[$match[1]][(int) $match[2]][$match[3]] ??= $value;
                }
            } elseif (preg_match('/^ctl00_body_(CX[A-Z0-9]+)$/', $elementId, $match) === 1) {
                $fields[$match[1]] ??= $value;
            }
        }

        return [$fields, $repeaters];
    }

    private function multiline(string $text): ?string
    {
        $lines = array_map(
            static fn (string $line): string => trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $line)),
            explode("\n", $text),
        );
        $text = trim(implode("\n", array_filter($lines, static fn (string $line): bool => $line !== '')));

        return $text === '' ? null : $text;
    }

    /**
     * @param  array<string, array<int, array<string, string>>>  $repeaters
     * @return list<string>
     */
    private function column(array $repeaters, string $group, string $field): array
    {
        $values = [];

        foreach ($repeaters[$group] ?? [] as $row) {
            if (isset($row[$field])) {
                $values[] = $row[$field];
            }
        }

        return $values;
    }

    /**
     * Additional trial identifiers and the UTN; IdentifierParser recognizes NCT, EudraCT, UTN
     * and other registry numbers among them and ignores sponsor protocol numbers.
     *
     * @param  array<string, string>  $fields
     * @param  array<string, array<int, array<string, string>>>  $repeaters
     * @return list<array<string, string|null>>
     */
    private function secondaryIds(array $fields, array $repeaters): array
    {
        $pairs = [];

        foreach ($this->column($repeaters, 'SECONDARYID', 'CXSECONDARYID') as $value) {
            $pairs[] = [$value, null];
        }

        $pairs[] = [$fields['CXUTRN'] ?? null, 'UTN'];

        return $this->secondaryIdRows(array_filter(
            $pairs,
            fn (array $pair): bool => $pair[0] !== null && ! in_array(strtolower(trim($pair[0])), self::NO_VALUE, true),
        ));
    }

    /**
     * Secondary sponsors; the type "None" with no name means there are none.
     *
     * @param  array<string, array<int, array<string, string>>>  $repeaters
     * @return list<array<string, string|null>>
     */
    private function collaborators(array $repeaters): array
    {
        $rows = [];

        foreach ($this->unique($this->column($repeaters, 'SECONDARYSPONSOR', 'CXNAME')) as $name) {
            $rows[] = ['name' => $name, 'class' => null];
        }

        return $rows;
    }

    /**
     * @param  array<string, string>  $fields
     * @param  array<string, array<int, array<string, string>>>  $repeaters
     * @return list<array<string, string|null>>
     */
    private function interventions(array $fields, array $repeaters): array
    {
        $description = $this->text($fields['CXINTERVENTIONS'] ?? null);

        if ($description === null) {
            return [];
        }

        $codes = $this->unique($this->column($repeaters, 'INTERVENTIONCODE', 'CXINTERVENTIONCODE'));

        return [[
            'type' => $codes === [] ? null : $this->short(implode(', ', $codes)),
            'name' => $this->short($description),
            'description' => $description,
        ]];
    }

    /**
     * @param  array<string, array<int, array<string, string>>>  $repeaters
     * @return list<array<string, string|null>>
     */
    private function outcomes(array $repeaters, string $group, string $type): array
    {
        $rows = [];

        foreach ($repeaters[$group] ?? [] as $row) {
            $measure = $row['CXOUTCOME'] ?? null;

            if ($this->short($measure) !== null) {
                $rows[] = [
                    'type' => $type,
                    'measure' => $this->short($measure),
                    'description' => $this->text($measure),
                    'time_frame' => $this->short($row['CXTIMEPOINT'] ?? null),
                ];
            }
        }

        return $rows;
    }

    /**
     * Australian recruitment states, one row each; hospitals and recruitment outside Australia
     * are not read (no recorded page shows their markup).
     *
     * @param  array<string, string>  $fields
     * @return list<array<string, mixed>>
     */
    private function locations(array $fields): array
    {
        $states = $this->unique(explode(',', (string) ($fields['CXRECRUITMENTSTATE'] ?? '')));

        return array_map(fn (string $state): array => [
            'facility' => null,
            'city' => null,
            'state' => $state,
            'country' => 'Australia',
            'status' => null,
            'zip' => null,
            'latitude' => null,
            'longitude' => null,
        ], $states);
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function eligibility(array $fields): ?string
    {
        $parts = [];

        foreach (['CXINCLUSIVECRITERIA' => 'Inclusion criteria', 'CXEXCLUSIVECRITERIA' => 'Exclusion criteria'] as $field => $label) {
            $text = $this->text($fields[$field] ?? null);

            if ($text !== null) {
                $parts[] = "{$label}:\n{$text}";
            }
        }

        return $parts === [] ? null : implode("\n\n", $parts);
    }

    private function status(?string $value): ?string
    {
        $key = strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $value)));

        return (self::STATUSES[$key] ?? null)?->value;
    }

    /** "Not Applicable", "Phase 1", "Phase 2 / Phase 3", "Phase 0" → NA, PHASE1, "PHASE2, PHASE3", EARLY_PHASE1. */
    private function phase(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));

        if ($value === '') {
            return null;
        }

        if (str_contains($value, 'not applicable')) {
            return StudyPhase::NotApplicable->value;
        }

        preg_match_all('/phase\s*([0-4])/', $value, $matches);
        $phases = array_map(static fn (string $number): string => match ($number) {
            '0' => StudyPhase::EarlyPhase1->value,
            '1' => StudyPhase::Phase1->value,
            '2' => StudyPhase::Phase2->value,
            '3' => StudyPhase::Phase3->value,
            default => StudyPhase::Phase4->value,
        }, $matches[1]);
        $phases = array_values(array_unique($phases));

        return $phases === [] ? null : implode(', ', $phases);
    }

    private function gender(?string $value): ?string
    {
        return match (strtolower(trim((string) $value))) {
            'both males and females', 'both' => 'ALL',
            'females', 'female' => 'FEMALE',
            'males', 'male' => 'MALE',
            default => null,
        };
    }

    /** "18" + "Years" → "18 Years"; "No limit" or no number means no limit. */
    private function age(?string $value, ?string $unit): ?string
    {
        if ($value === null || preg_match('/^\d+$/', trim($value)) !== 1 || (int) $value === 0) {
            return null;
        }

        $unit = $this->short($unit);

        return $unit !== null && strtolower($unit) !== 'no limit' ? trim($value).' '.$unit : trim($value);
    }

    private function count(?string $value): ?int
    {
        return $value !== null && preg_match('/^\d+$/', trim($value)) === 1 ? (int) $value : null;
    }

    /**
     * @param  list<string|null>  $values
     * @return list<string>
     */
    private function unique(array $values): array
    {
        $unique = [];

        foreach ($values as $value) {
            $value = $this->short($value);

            if ($value !== null) {
                $unique[mb_strtolower($value)] ??= $value;
            }
        }

        return array_values($unique);
    }

    /**
     * @param  list<string|null>  $values
     * @return list<array<string, string>>
     */
    private function rows(string $column, array $values): array
    {
        return array_map(fn (string $value): array => [$column => $value], $this->unique($values));
    }
}
