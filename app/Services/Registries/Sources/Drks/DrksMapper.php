<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Drks;

use App\Enums\StudyStatus;
use App\Exceptions\InvalidStudyPayload;
use App\Services\ClinicalTrials\Import\MappedStudy;
use App\Services\ClinicalTrials\Import\RawDocument;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\Registries\Identifiers\IdentifierParser;
use App\Services\Registries\Sources\MapsRegistryRecords;

/**
 * Maps one trial of the DRKS JSON export. Texts are bilingual (de/en); English is used when
 * present, German otherwise. Contact persons (names, emails, phones) are never imported and
 * are removed from the kept raw record.
 */
final class DrksMapper implements StudyPayloadMapper
{
    use MapsRegistryRecords;

    private const array STATUSES = [
        'PENDING' => StudyStatus::NotYetRecruiting,
        'RECRUITING' => StudyStatus::Recruiting,
        'INVITE_ONLY' => StudyStatus::EnrollingByInvitation,
        'SUSPENDED' => StudyStatus::Suspended,
        'DISCONTINIUED' => StudyStatus::Terminated, // sic, as spelled by DRKS
        'DISCONTINUED' => StudyStatus::Terminated,
        'WITHDRAWN' => StudyStatus::Withdrawn,
        'COMPLETE_FOLLOW_UP_CONTINUING' => StudyStatus::ActiveNotRecruiting,
        'COMPLETE_FOLLOW_UP_COMPLETE' => StudyStatus::Completed,
    ];

    private const array PHASES = [
        'PHASE_0' => 'EARLY_PHASE1',
        'PHASE_I' => 'PHASE1',
        'PHASE_I_II' => 'PHASE1, PHASE2',
        'PHASE_II' => 'PHASE2',
        'PHASE_II_III' => 'PHASE2, PHASE3',
        'PHASE_III' => 'PHASE3',
        'PHASE_IV' => 'PHASE4',
        'N_A' => 'NA',
    ];

    private const array STUDY_TYPES = [
        'INTERVENTIONAL' => 'INTERVENTIONAL',
        'NON_INTERVENTIONAL' => 'OBSERVATIONAL',
    ];

    private const array AGE_UNITS = ['YEARS' => 'Years', 'MONTHS' => 'Months', 'WEEKS' => 'Weeks', 'DAYS' => 'Days'];

    /** Personal fields of DRKS contact objects, dropped from the raw record. */
    private const array PERSONAL_FIELDS = ['firstName', 'lastName', 'title', 'email', 'phone', 'fax'];

    /** Countries DRKS records commonly list (ISO 3166-1 alpha-2); other codes are kept as given. */
    private const array COUNTRIES = [
        'AR' => 'Argentina', 'AT' => 'Austria', 'AU' => 'Australia', 'BE' => 'Belgium', 'BG' => 'Bulgaria',
        'BR' => 'Brazil', 'CA' => 'Canada', 'CH' => 'Switzerland', 'CN' => 'China', 'CY' => 'Cyprus',
        'CZ' => 'Czechia', 'DE' => 'Germany', 'DK' => 'Denmark', 'EE' => 'Estonia', 'EG' => 'Egypt',
        'ES' => 'Spain', 'FI' => 'Finland', 'FR' => 'France', 'GB' => 'United Kingdom', 'GR' => 'Greece',
        'HR' => 'Croatia', 'HU' => 'Hungary', 'IE' => 'Ireland', 'IL' => 'Israel', 'IN' => 'India',
        'IS' => 'Iceland', 'IT' => 'Italy', 'JP' => 'Japan', 'KR' => 'South Korea', 'LI' => 'Liechtenstein',
        'LT' => 'Lithuania', 'LU' => 'Luxembourg', 'LV' => 'Latvia', 'MT' => 'Malta', 'MX' => 'Mexico',
        'NL' => 'Netherlands', 'NO' => 'Norway', 'NZ' => 'New Zealand', 'PL' => 'Poland', 'PT' => 'Portugal',
        'RO' => 'Romania', 'RS' => 'Serbia', 'RU' => 'Russia', 'SE' => 'Sweden', 'SI' => 'Slovenia',
        'SK' => 'Slovakia', 'TR' => 'Turkey', 'UA' => 'Ukraine', 'US' => 'United States', 'VA' => 'Holy See',
        'ZA' => 'South Africa',
    ];

    public function payloadId(array $payload): ?string
    {
        $id = $payload['drksId'] ?? null;

        return is_string($id) && preg_match('/^DRKS\d{8}$/', $id) === 1 ? $id : null;
    }

    public function map(array $payload): MappedStudy
    {
        $id = $this->payloadId($payload) ?? throw new InvalidStudyPayload('The DRKS record has no valid drksId.');

        $description = $this->localized($payload['trialDescriptions'] ?? null);
        $recruitment = $this->array($payload['recruitment'] ?? null);
        $characteristic = $this->array($payload['studyCharacteristic'] ?? null);
        $status = self::STATUSES[$recruitment['status'] ?? ''] ?? null;
        $results = $this->array($payload['trialResults'] ?? null);
        $resultsText = $this->localized($results['trialResultsDescriptions'] ?? null);
        $actualCount = $recruitment['totalParticipants'] ?? null;
        $plannedCount = $recruitment['plannedParticipants'] ?? null;

        $dates = [
            'start_date' => $this->date($recruitment['actualStartDate'] ?? null) ?? $this->date($recruitment['scheduledStartDate'] ?? null),
            'primary_completion_date' => null,
            'completion_date' => $this->date($recruitment['actualCompletionDate'] ?? null) ?? $this->date($recruitment['scheduledCompletionDate'] ?? null),
        ];

        $attributes = [
            'brief_title' => $this->short($description['title'] ?? null) ?? $id,
            'official_title' => null,
            'acronym' => $this->short($description['acronym'] ?? null),
            'org_study_id' => null,
            'overall_status' => $status?->value,
            'status_verified_date' => null,
            ...$dates,
            'study_first_post_date' => $this->date($payload['registrationDrks'] ?? null),
            'last_update_post_date' => $this->date($payload['lastUpdate'] ?? null),
            'has_expanded_access' => false,
            'brief_summary' => $this->text($description['summary'] ?? null),
            'detailed_description' => $this->text($description['scientificSummary'] ?? null),
            'study_type' => self::STUDY_TYPES[$characteristic['type'] ?? ''] ?? null,
            'phase' => self::PHASES[$characteristic['phase'] ?? ''] ?? null,
            'enrollment_count' => is_int($actualCount) ? $actualCount : (is_int($plannedCount) ? $plannedCount : null),
            'enrollment_type' => is_int($actualCount) ? 'ACTUAL' : (is_int($plannedCount) ? 'ESTIMATED' : null),
            'sponsor_name' => $this->contactsOfType($payload, 'PRIMARY_SPONSOR')[0] ?? null,
            'sponsor_class' => null,
            'responsible_party_type' => null,
            'eligibility_criteria' => $this->eligibility($this->localized($recruitment['recruitmentDescriptions'] ?? null)),
            'min_age' => $this->age($recruitment['minimumAge'] ?? null),
            'max_age' => $this->age($recruitment['maximumAge'] ?? null),
            'gender' => in_array($recruitment['gender'] ?? null, ['ALL', 'FEMALE', 'MALE'], true) ? $recruitment['gender'] : null,
            'is_fda_regulated_drug' => false,
            'is_fda_regulated_device' => false,
            'ipd_sharing' => match ($results['ipdSharingPlan'] ?? null) {
                true => 'YES',
                false => 'NO',
                default => null,
            },
            'has_results' => $this->text($resultsText['briefSummaryOfResultsDescription'] ?? null) !== null,
        ];

        $relations = $this->emptyRelations();
        $relations['secondaryIds'] = $this->secondaryIds($payload);
        $relations['collaborators'] = array_map(
            fn (string $name): array => ['name' => $name, 'class' => null],
            $this->contactsOfType($payload, 'SECONDARY_SPONSOR'),
        );
        $relations['conditions'] = $this->conditions($payload['studiedHealthConditions'] ?? null);
        $relations['locations'] = $this->locations($recruitment);
        $relations['armGroups'] = $this->armGroups($payload['observationalGroups'] ?? null, $attributes['study_type']);
        $relations['interventions'] = $attributes['study_type'] === 'INTERVENTIONAL'
            ? array_map(fn (array $arm): array => ['type' => null, 'name' => $arm['description'] ?? $arm['label'], 'description' => null], $relations['armGroups'])
            : [];
        $relations['outcomes'] = $this->outcomes($this->localized($characteristic['characteristicDescriptions'] ?? null));

        return new MappedStudy(
            source: DrksSource::KEY,
            sourceId: $id,
            attributes: $attributes,
            relations: $relations,
            facts: $this->facts($status?->value, $relations['locations'], $dates, $attributes['has_results']),
            raw: new RawDocument(
                'json',
                json_encode($this->withoutPersonalData($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                'https://drks.de/search/en/trial/'.$id,
            ),
        );
    }

    /**
     * The English entry of a bilingual list, else the German one, else the first.
     *
     * @return array<string, mixed>
     */
    private function localized(mixed $entries): array
    {
        if (! is_array($entries)) {
            return [];
        }

        $byLocale = [];

        foreach ($entries as $entry) {
            if (is_array($entry)) {
                $locale = $entry['idLocale']['locale'] ?? $entry['idArm']['locale'] ?? null;
                $byLocale[is_string($locale) ? $locale : ''] ??= $entry;
            }
        }

        return $byLocale['en'] ?? $byLocale['de'] ?? (array_values($byLocale)[0] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * Organisation names of the contacts of one type (sponsors are organisations, not people).
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function contactsOfType(array $payload, string $type): array
    {
        $names = [];

        foreach ($this->array($payload['trialContacts'] ?? null) as $contact) {
            if (is_array($contact) && ($contact['idContactIdType']['type'] ?? null) === $type) {
                $name = $this->short($contact['contact']['affiliation'] ?? null);

                if ($name !== null) {
                    $names[$name] = $name;
                }
            }
        }

        return array_values($names);
    }

    /**
     * @param  array<string, mixed>  $description
     */
    private function eligibility(array $description): ?string
    {
        $parts = array_filter([
            ($inclusion = $this->text($description['inclusionCriteria'] ?? null)) !== null ? "Inclusion criteria:\n".$inclusion : null,
            ($exclusion = $this->text($description['exclusionCriteria'] ?? null)) !== null ? "Exclusion criteria:\n".$exclusion : null,
        ]);

        return $parts === [] ? null : implode("\n\n", $parts);
    }

    private function age(mixed $age): ?string
    {
        if (! is_array($age) || ! is_int($age['amount'] ?? null) || ! isset(self::AGE_UNITS[$age['unit'] ?? ''])) {
            return null;
        }

        return $age['amount'].' '.self::AGE_UNITS[$age['unit']];
    }

    /**
     * Identifiers of the same study in other registries. Free-text secondary ids (sponsor
     * protocol numbers, authority file numbers) are kept only when they are a registry
     * identifier, so they can never link unrelated studies.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array<string, string|null>>
     */
    private function secondaryIds(array $payload): array
    {
        $ids = $this->array($payload['secondaryIds'] ?? null);
        $pairs = [
            [$ids['otherPrimaryRegisterId'] ?? null, $ids['otherPrimaryRegisterName'] ?? 'Other primary register'],
            [$ids['eudraCtNumber'] ?? null, 'EudraCT'],
            [$ids['universalTrialNumber'] ?? null, 'UTN'],
            [$ids['eudamedNumber'] ?? null, 'EUDAMED'],
        ];

        $parser = new IdentifierParser;

        foreach ($this->array($payload['otherSecondaryIds'] ?? null) as $other) {
            $value = is_array($other) ? ($other['value'] ?? null) : null;

            if (is_string($value) && $parser->parse($value) !== null) {
                $pairs[] = [$value, is_string($other['description'] ?? null) ? $other['description'] : null];
            }
        }

        return $this->secondaryIdRows($pairs);
    }

    /**
     * @return list<array{condition: string}>
     */
    private function conditions(mixed $conditions): array
    {
        $rows = [];

        foreach (is_array($conditions) ? $conditions : [] as $condition) {
            if (! is_array($condition)) {
                continue;
            }

            $comment = $this->short($this->localized($condition['studiedHealthConditionDescriptions'] ?? null)['comment'] ?? null);
            $code = $this->short($condition['code'] ?? null);
            $name = $code !== null && $comment !== null ? "{$code} - {$comment}" : ($comment ?? $code);
            $name = $this->short($name);

            if ($name !== null) {
                $rows[$name] = ['condition' => $name];
            }
        }

        return array_values($rows);
    }

    /**
     * Recruiting institutes, with the country when the study recruits in one country only,
     * and one row per country otherwise.
     *
     * @param  array<string, mixed>  $recruitment
     * @return list<array<string, mixed>>
     */
    private function locations(array $recruitment): array
    {
        $countries = [];

        foreach ($this->array($recruitment['countries'] ?? null) as $country) {
            $code = is_array($country) ? $this->short($country['idCountry']['code'] ?? null) : null;

            if ($code !== null) {
                $countries[$code] = self::COUNTRIES[strtoupper($code)] ?? $code;
            }
        }

        $single = count($countries) === 1 ? array_values($countries)[0] : null;
        $rows = [];

        foreach ($this->array($recruitment['institutes'] ?? null) as $institute) {
            if (! is_array($institute)) {
                continue;
            }

            $facility = $this->short($institute['name'] ?? null);
            $city = $this->short($institute['city'] ?? null);

            if ($facility !== null || $city !== null) {
                $rows[] = $this->location($facility, $city, $single);
            }
        }

        if ($single === null || $rows === []) {
            foreach ($countries as $name) {
                $rows[] = $this->location(null, null, $name);
            }
        }

        return array_values(array_unique($rows, SORT_REGULAR));
    }

    /**
     * @return array<string, mixed>
     */
    private function location(?string $facility, ?string $city, ?string $country): array
    {
        return [
            'facility' => $facility,
            'city' => $city,
            'state' => null,
            'country' => $country,
            'status' => null,
            'zip' => null,
            'latitude' => null,
            'longitude' => null,
        ];
    }

    /**
     * Arms of an interventional study or groups of an observational one, one row per arm.
     *
     * @return list<array<string, string|null>>
     */
    private function armGroups(mixed $groups, ?string $studyType): array
    {
        $byArm = [];

        foreach (is_array($groups) ? $groups : [] as $group) {
            $arm = is_array($group) ? ($group['idArm']['arm'] ?? null) : null;

            if (is_int($arm)) {
                $byArm[$arm][] = $group;
            }
        }

        ksort($byArm);
        $label = $studyType === 'INTERVENTIONAL' ? 'Arm' : 'Group';
        $rows = [];

        foreach ($byArm as $arm => $entries) {
            $rows[] = [
                'label' => "{$label} {$arm}",
                'type' => null,
                'description' => $this->text($this->localized($entries)['comment'] ?? null),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $description
     * @return list<array<string, string|null>>
     */
    private function outcomes(array $description): array
    {
        $rows = [];

        foreach (['PRIMARY' => 'primaryOutcome', 'SECONDARY' => 'secondaryOutcome'] as $type => $key) {
            $measure = $this->text($description[$key] ?? null);

            if ($measure !== null) {
                $rows[] = ['type' => $type, 'measure' => $measure, 'description' => null, 'time_frame' => null];
            }
        }

        return $rows;
    }

    /**
     * The record with every contact person's name, email, phone and fax removed, and without
     * embedded files.
     *
     * @param  array<array-key, mixed>  $node
     * @return array<array-key, mixed>
     */
    private function withoutPersonalData(array $node): array
    {
        $isContact = array_key_exists('affiliation', $node);

        foreach ($node as $key => $value) {
            // Embedded publication files (base64) are left out too: they can weigh megabytes.
            if (($isContact && in_array($key, self::PERSONAL_FIELDS, true)) || $key === 'base64Encoded') {
                unset($node[$key]);
            } elseif (is_array($value)) {
                $node[$key] = $this->withoutPersonalData($value);
            }
        }

        return $node;
    }
}
