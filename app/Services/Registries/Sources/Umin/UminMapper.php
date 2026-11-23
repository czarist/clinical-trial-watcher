<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Umin;

use App\Enums\IdentifierScheme;
use App\Enums\StudyStatus;
use App\Exceptions\InvalidStudyPayload;
use App\Services\ClinicalTrials\Import\MappedStudy;
use App\Services\ClinicalTrials\Import\RawDocument;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\Registries\Identifiers\IdentifierParser;
use App\Services\Registries\Sources\MapsRegistryRecords;

/**
 * Maps one row of the UMIN-CTR English CSV dump (keyed by its header) to the common study model.
 *
 * UNVERIFIED: no sample of the dump could be read (UMIN's download host disallows AI agents in
 * robots.txt), and UMIN does not document the CSV columns. The mapping is header-driven: each
 * field is looked up by the candidate column names below, taken from the labels of UMIN-CTR's
 * English registration form, and is null when no candidate matches. Check every constant and
 * vocabulary against the first real dump before relying on the data.
 *
 * Columns holding personal data (names, emails, phones, addresses of contact persons and
 * investigators) are never imported and are removed from the kept raw row.
 */
final class UminMapper implements StudyPayloadMapper
{
    use MapsRegistryRecords;

    /** The UMIN trial number, UMIN000000000. Unverified column name. */
    public const array ID = ['Unique ID issued by UMIN', 'UMIN ID', 'Unique trial number'];

    public const array PUBLIC_TITLE = ['Public title', 'Official scientific title of the study'];

    public const array SCIENTIFIC_TITLE = ['Scientific Title', 'Title of the study'];

    public const array ACRONYM = ['Scientific Title:Acronym', 'Title of the study (Brief title)', 'Acronym'];

    public const array STATUS = ['Recruitment status'];

    public const array CONDITION = ['Condition'];

    public const array PHASE = ['Developmental phase'];

    public const array STUDY_TYPE = ['Study type'];

    public const array SPONSOR = ['Name of primary sponsor', 'Primary sponsor'];

    public const array GENDER = ['Gender'];

    public const array AGE_LOWER = ['Age-lower limit'];

    public const array AGE_UPPER = ['Age-upper limit'];

    public const array INCLUSION = ['Key inclusion criteria'];

    public const array EXCLUSION = ['Key exclusion criteria'];

    public const array SAMPLE_SIZE = ['Target sample size'];

    public const array OBJECTIVES = ['Narrative objectives1', 'Objectives'];

    public const array PRIMARY_OUTCOME = ['Primary outcomes'];

    public const array SECONDARY_OUTCOME = ['Key secondary outcomes'];

    /** The registration date ICTRP uses (named in UMIN's documentation). */
    public const array DISCLOSURE_DATE = ['Date of disclosure of the study information'];

    /** Named in UMIN's documentation. */
    public const array START_DATE = ['Anticipated trial start date'];

    public const array COMPLETION_DATE = ['Last follow-up date', 'Date trial data considered complete'];

    public const array LAST_MODIFIED = ['Last modified on', 'Last modified date'];

    /** Interventions are spread over numbered columns (Interventions/Control_1, _2...). */
    public const string INTERVENTION_PREFIX = 'Interventions/Control';

    /** Columns whose header names an identifier ("Study ID_1", "Secondary IDs"...) are searched for registry identifiers. */
    public const string IDENTIFIER_HEADER = '/\bIDs?(?![A-Za-z])/';

    /** Headers of columns with personal data, never imported. */
    public const string PERSONAL_HEADER = '/e-?mail|\bTEL\b|phone|fax|address|zip|first name|middle name|last name|person|investigator|contact/i';

    /** Recruitment status labels (unverified, from prior knowledge of the UMIN-CTR form). */
    private const array STATUSES = [
        'preinitiation' => StudyStatus::NotYetRecruiting,
        'open public recruiting' => StudyStatus::Recruiting,
        'recruiting' => StudyStatus::Recruiting,
        'enrolling by invitation' => StudyStatus::EnrollingByInvitation,
        'no longer recruiting' => StudyStatus::ActiveNotRecruiting,
        'completed' => StudyStatus::Completed,
        'main results already published' => StudyStatus::Completed,
        'suspended' => StudyStatus::Suspended,
        'terminated' => StudyStatus::Terminated,
    ];

    private const array ROMAN_PHASES = ['I' => 'PHASE1', 'II' => 'PHASE2', 'III' => 'PHASE3', 'IV' => 'PHASE4'];

    /**
     * The value of the first candidate column present in the row (header names compared
     * case-insensitively), or null.
     *
     * @param  array<string, string|null>  $row
     * @param  list<string>  $candidates
     */
    public static function value(array $row, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (array_key_exists($candidate, $row)) {
                return $row[$candidate];
            }
        }

        $lower = array_change_key_case($row, CASE_LOWER);

        foreach ($candidates as $candidate) {
            if (array_key_exists(strtolower($candidate), $lower)) {
                return $lower[strtolower($candidate)];
            }
        }

        return null;
    }

    /**
     * Whether a header row has the record identifier column.
     *
     * @param  list<string>  $header
     */
    public static function hasIdColumn(array $header): bool
    {
        return self::value(array_fill_keys($header, ''), self::ID) !== null;
    }

    public function payloadId(array $payload): ?string
    {
        $identifier = (new IdentifierParser)->parse(self::value($payload, self::ID));

        return $identifier?->scheme === IdentifierScheme::Umin ? $identifier->value : null;
    }

    public function map(array $payload): MappedStudy
    {
        $id = $this->payloadId($payload) ?? throw new InvalidStudyPayload('The UMIN-CTR row has no valid UMIN trial number.');

        $status = self::STATUSES[strtolower(trim((string) self::value($payload, self::STATUS)))] ?? null;
        $sampleSize = self::value($payload, self::SAMPLE_SIZE);
        $enrollment = $sampleSize !== null && ctype_digit(trim($sampleSize)) && (int) $sampleSize > 0 ? (int) $sampleSize : null;

        $dates = [
            'start_date' => $this->registryDate(self::value($payload, self::START_DATE)),
            'primary_completion_date' => null,
            'completion_date' => $this->registryDate(self::value($payload, self::COMPLETION_DATE)),
        ];

        $attributes = [
            'brief_title' => $this->short(self::value($payload, self::PUBLIC_TITLE)) ?? $this->short(self::value($payload, self::SCIENTIFIC_TITLE)) ?? $id,
            'official_title' => $this->text(self::value($payload, self::SCIENTIFIC_TITLE)),
            'acronym' => $this->short(self::value($payload, self::ACRONYM)),
            'org_study_id' => null,
            'overall_status' => $status?->value,
            'status_verified_date' => null,
            ...$dates,
            'study_first_post_date' => $this->registryDate(self::value($payload, self::DISCLOSURE_DATE)),
            'last_update_post_date' => $this->registryDate(self::value($payload, self::LAST_MODIFIED)),
            'has_expanded_access' => false,
            'brief_summary' => $this->text(self::value($payload, self::OBJECTIVES)),
            'detailed_description' => null,
            'study_type' => match (strtolower(trim((string) self::value($payload, self::STUDY_TYPE)))) {
                'interventional' => 'INTERVENTIONAL',
                'observational' => 'OBSERVATIONAL',
                default => null,
            },
            'phase' => $this->phase(self::value($payload, self::PHASE)),
            'enrollment_count' => $enrollment,
            'enrollment_type' => $enrollment !== null ? 'ESTIMATED' : null,
            'sponsor_name' => $this->short(self::value($payload, self::SPONSOR)),
            'sponsor_class' => null,
            'responsible_party_type' => null,
            'eligibility_criteria' => $this->eligibility($payload),
            'min_age' => $this->age(self::value($payload, self::AGE_LOWER)),
            'max_age' => $this->age(self::value($payload, self::AGE_UPPER)),
            'gender' => match (strtolower(trim((string) self::value($payload, self::GENDER)))) {
                'male and female', 'both', 'all' => 'ALL',
                'male' => 'MALE',
                'female' => 'FEMALE',
                default => null,
            },
            'is_fda_regulated_drug' => false,
            'is_fda_regulated_device' => false,
            'ipd_sharing' => null,
            'has_results' => false,
        ];

        $relations = $this->emptyRelations();
        $relations['secondaryIds'] = $this->secondaryIds($payload, $id);
        $relations['conditions'] = ($condition = $this->short(self::value($payload, self::CONDITION))) !== null ? [['condition' => $condition]] : [];
        $relations['interventions'] = $this->interventions($payload);
        $relations['outcomes'] = $this->outcomes($payload);

        return new MappedStudy(
            source: UminSource::KEY,
            sourceId: $id,
            attributes: $attributes,
            relations: $relations,
            facts: $this->facts($status?->value, [], $dates),
            raw: new RawDocument('csv', $this->rawRow($payload), UminClient::DUMP_URL),
        );
    }

    /** A date written 2024/06/01, 2024/06 or 2024 (format unverified), with dashes for the shared parser. */
    private function registryDate(?string $value): ?string
    {
        return $value === null ? null : $this->date(str_replace('/', '-', trim($value)));
    }

    /** "Phase I,II" → "PHASE1, PHASE2"; "Not applicable" → "NA". */
    private function phase(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('/not applicable/i', $value) === 1) {
            return 'NA';
        }

        if (preg_match('/phase\s*([IV]+(?:\s*[,\/-]\s*[IV]+)*)/i', $value, $match) !== 1) {
            return null;
        }

        $phases = [];

        foreach (preg_split('/\s*[,\/-]\s*/', strtoupper($match[1])) ?: [] as $roman) {
            if (isset(self::ROMAN_PHASES[$roman])) {
                $phases[] = self::ROMAN_PHASES[$roman];
            }
        }

        $phases = array_values(array_unique($phases));
        sort($phases);

        return $phases === [] ? null : implode(', ', $phases);
    }

    /** "20years-old" or "20 years-old <=" → "20 Years"; "Not applicable" → null. */
    private function age(?string $value): ?string
    {
        if ($value === null || preg_match('/(\d+(?:\.\d+)?)\s*(year|month|week|day|hour)s?/i', $value, $match) !== 1) {
            return null;
        }

        return $match[1].' '.ucfirst(strtolower($match[2])).'s';
    }

    /**
     * @param  array<string, string|null>  $payload
     */
    private function eligibility(array $payload): ?string
    {
        $sections = array_filter([
            'Inclusion criteria' => $this->text(self::value($payload, self::INCLUSION)),
            'Exclusion criteria' => $this->text(self::value($payload, self::EXCLUSION)),
        ]);

        if ($sections === []) {
            return null;
        }

        return implode("\n\n", array_map(fn (string $heading, string $text): string => $heading.":\n".$text, array_keys($sections), $sections));
    }

    /**
     * Registry identifiers (NCT, jRCT...) cited in any identifier column, other than the
     * record's own UMIN number.
     *
     * @param  array<string, string|null>  $payload
     * @return list<array<string, string|null>>
     */
    private function secondaryIds(array $payload, string $id): array
    {
        $parser = new IdentifierParser;
        $ownColumns = array_map('strtolower', self::ID);
        $pairs = [];

        foreach ($payload as $header => $value) {
            if ($value === null || in_array(strtolower((string) $header), $ownColumns, true) || preg_match(self::IDENTIFIER_HEADER, (string) $header) !== 1) {
                continue;
            }

            foreach (preg_split('/[\s,;]+/', $value) ?: [] as $token) {
                $identifier = $parser->parse($token);

                if ($identifier !== null && $identifier->value !== $id) {
                    $pairs[] = [$identifier->value, strtoupper($identifier->scheme->value)];
                }
            }
        }

        return $this->secondaryIdRows($pairs);
    }

    /**
     * @param  array<string, string|null>  $payload
     * @return list<array<string, string|null>>
     */
    private function interventions(array $payload): array
    {
        $rows = [];

        foreach ($payload as $header => $value) {
            if (! str_starts_with((string) $header, self::INTERVENTION_PREFIX)) {
                continue;
            }

            $text = $this->text($value);

            if ($text !== null) {
                // The column holds free text; its first line is used as the name.
                $name = (string) $this->short(strtok($text, "\n"));
                $rows[$name] ??= ['type' => null, 'name' => $name, 'description' => $text !== $name ? $text : null];
            }
        }

        return array_values($rows);
    }

    /**
     * @param  array<string, string|null>  $payload
     * @return list<array<string, string|null>>
     */
    private function outcomes(array $payload): array
    {
        $rows = [];

        foreach (['PRIMARY' => self::PRIMARY_OUTCOME, 'SECONDARY' => self::SECONDARY_OUTCOME] as $type => $columns) {
            $measure = $this->text(self::value($payload, $columns));

            if ($measure !== null) {
                $rows[] = ['type' => $type, 'measure' => $measure, 'description' => null, 'time_frame' => null];
            }
        }

        return $rows;
    }

    /**
     * The row as CSV (header line and value line), without the personal-data columns.
     *
     * @param  array<string, string|null>  $payload
     */
    private function rawRow(array $payload): string
    {
        $kept = array_filter($payload, fn (string|int $header): bool => preg_match(self::PERSONAL_HEADER, (string) $header) !== 1, ARRAY_FILTER_USE_KEY);
        $handle = fopen('php://temp', 'r+b');

        if ($handle === false) {
            return '';
        }

        fputcsv($handle, array_map('strval', array_keys($kept)), ',', '"', '');
        fputcsv($handle, array_map(fn (?string $value): string => (string) $value, array_values($kept)), ',', '"', '');
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
