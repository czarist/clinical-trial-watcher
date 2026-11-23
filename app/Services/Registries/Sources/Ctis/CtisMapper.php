<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Ctis;

use App\Enums\StudyStatus;
use App\Exceptions\InvalidStudyPayload;
use App\Services\ClinicalTrials\Import\MappedStudy;
use App\Services\ClinicalTrials\Import\RawDocument;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\Registries\Identifiers\IdentifierParser;
use App\Services\Registries\Sources\MapsRegistryRecords;
use Illuminate\Support\Arr;

/**
 * Maps one CTIS trial (the retrieve document, with the trial's search result under
 * `searchResult`). Contact persons, site investigators and their emails and phones are never
 * imported and are removed from the kept raw record.
 */
final class CtisMapper implements StudyPayloadMapper
{
    use MapsRegistryRecords;

    /**
     * Public trial status codes (`ctPublicStatusCode`, also `ctStatus` of the search results).
     * EMA publishes no code list: only the codes whose meaning the samples show are mapped, and
     * every other code (8 and 11 were seen too) maps to null until its label is confirmed.
     */
    private const array STATUS_CODES = [
        // Seen on authorised trials whose recruitment has not started in any member state.
        2 => StudyStatus::NotYetRecruiting,
        // Unverified: seen on an ongoing trial whose recruitment had ended ("Ongoing, recruitment ended").
        5 => StudyStatus::ActiveNotRecruiting,
    ];

    /**
     * Status labels of CTIS, used when the payload has no public status code. All unverified
     * except "Authorised", which is the application status and says nothing about recruitment.
     */
    private const array STATUS_LABELS = [
        'authorised, recruitment pending' => StudyStatus::NotYetRecruiting,
        'authorised, recruiting' => StudyStatus::Recruiting,
        'ongoing, recruiting' => StudyStatus::Recruiting,
        'ongoing, recruitment ended' => StudyStatus::ActiveNotRecruiting,
        'temporarily halted' => StudyStatus::Suspended,
        'halted' => StudyStatus::Suspended,
        'ended' => StudyStatus::Completed,
        'ended prematurely' => StudyStatus::Terminated,
        'early terminated' => StudyStatus::Terminated,
        'withdrawn' => StudyStatus::Withdrawn,
    ];

    /**
     * Trial phase codes (`trialCategory.trialPhase`), used when the search result's phase label
     * is absent. Codes 2 (Phase I, bioequivalence) and 5 (Phase III) are confirmed by the portal's
     * labels; the others follow the portal's order and are unverified.
     */
    private const array PHASE_CODES = [
        '1' => 'PHASE1', '2' => 'PHASE1', '3' => 'PHASE1',
        '4' => 'PHASE2',
        '5' => 'PHASE3',
        '6' => 'PHASE4',
    ];

    private const array ROMAN_PHASES = ['I' => 'PHASE1', 'II' => 'PHASE2', 'III' => 'PHASE3', 'IV' => 'PHASE4'];

    /** Keys holding personal data, dropped wherever they appear in the raw record. */
    private const array PERSONAL_KEYS = [
        'personInfo', 'publicContacts', 'scientificContacts', 'functionalName', 'functionalEmailAddress',
        'telephone', 'email', 'phone', 'phoneNumber', 'fax', 'firstName', 'lastName',
    ];

    public function payloadId(array $payload): ?string
    {
        $id = $payload['ctNumber'] ?? null;

        return is_string($id) && preg_match(CtisSource::ID_PATTERN, $id) === 1 ? $id : null;
    }

    public function map(array $payload): MappedStudy
    {
        $id = $this->payloadId($payload) ?? throw new InvalidStudyPayload('The CTIS record has no valid EU CT number (ctNumber).');

        $application = $this->array($payload['authorizedApplication'] ?? null);
        $partI = $this->array($application['authorizedPartI'] ?? null);
        $partsII = $this->list($application['authorizedPartsII'] ?? null);
        $identifiers = $this->array(Arr::get($partI, 'trialDetails.clinicalTrialIdentifiers'));
        $information = $this->array(Arr::get($partI, 'trialDetails.trialInformation'));
        $search = $this->array($payload['searchResult'] ?? null);
        $population = $this->array($information['populationOfTrialSubjects'] ?? null);
        $status = $this->status($payload);
        $sponsor = $this->primarySponsor($partI);

        $dates = [
            'start_date' => $this->date($payload['startDateEU'] ?? null)
                ?? $this->earliestStart($partsII)
                ?? $this->date(Arr::get($information, 'trialDuration.estimatedRecruitmentStartDate')),
            'primary_completion_date' => null,
            'completion_date' => $this->date(Arr::get($information, 'trialDuration.estimatedGlobalEndDate'))
                ?? $this->date(Arr::get($information, 'trialDuration.estimatedEndDate')),
        ];

        $enrollment = $this->enrollment($partI, $partsII);

        $attributes = [
            'brief_title' => $this->short($identifiers['publicTitle'] ?? null) ?? $this->short($identifiers['fullTitle'] ?? null) ?? $id,
            'official_title' => $this->text($identifiers['fullTitle'] ?? null),
            'acronym' => $this->short($identifiers['shortTitle'] ?? null),
            'org_study_id' => null,
            'overall_status' => $status?->value,
            'status_verified_date' => null,
            ...$dates,
            'study_first_post_date' => $this->date($payload['decisionDate'] ?? null),
            'last_update_post_date' => CtisClient::searchDate($search['lastUpdated'] ?? null)?->toDateString()
                ?? $this->date($payload['publishDate'] ?? null),
            'has_expanded_access' => false,
            'brief_summary' => $this->text(Arr::get($information, 'trialObjective.mainObjective')),
            'detailed_description' => null,
            // Every trial authorised under Regulation 536/2014 is an interventional trial of a medicine.
            'study_type' => 'INTERVENTIONAL',
            'phase' => $this->phase($search['trialPhase'] ?? null, Arr::get($information, 'trialCategory.trialPhase')),
            'enrollment_count' => $enrollment,
            'enrollment_type' => $enrollment !== null ? 'ESTIMATED' : null,
            'sponsor_name' => $this->short(Arr::get($sponsor, 'organisation.name')),
            'sponsor_class' => match ($sponsor['isCommercial'] ?? null) {
                true => 'INDUSTRY',
                false => 'OTHER',
                default => null,
            },
            'responsible_party_type' => null,
            'eligibility_criteria' => $this->eligibility($this->array($information['eligibilityCriteria'] ?? null)),
            'min_age' => null,
            'max_age' => null,
            'gender' => match ([$population['isFemaleSubjects'] ?? null, $population['isMaleSubjects'] ?? null]) {
                [true, true] => 'ALL',
                [true, false] => 'FEMALE',
                [false, true] => 'MALE',
                default => null,
            },
            'is_fda_regulated_drug' => false,
            'is_fda_regulated_device' => false,
            'ipd_sharing' => null,
            'has_results' => ($search['resultsFirstReceived'] ?? null) === 'Yes' || $this->array($payload['results'] ?? null) !== [],
        ];

        $relations = $this->emptyRelations();
        $relations['secondaryIds'] = $this->secondaryIds($application, $identifiers);
        $relations['collaborators'] = $this->collaborators($partI, $sponsor);
        $relations['conditions'] = $this->conditions($partI);
        $relations['keywords'] = $this->keywords($partI, $information);
        $relations['interventions'] = $this->interventions($partI);
        $relations['locations'] = $this->locations($partsII);
        $relations['outcomes'] = $this->outcomes($this->array($information['endPoint'] ?? null));

        return new MappedStudy(
            source: CtisSource::KEY,
            sourceId: $id,
            attributes: $attributes,
            relations: $relations,
            facts: $this->facts($status?->value, $relations['locations'], $dates, $attributes['has_results']),
            raw: new RawDocument(
                'json',
                json_encode($this->withoutPersonalData($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                'https://euclinicaltrials.eu/ctis-public-api/retrieve/'.$id,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function status(array $payload): ?StudyStatus
    {
        $code = $payload['ctPublicStatusCode'] ?? Arr::get($payload, 'searchResult.ctStatus');

        if ((is_int($code) || (is_string($code) && ctype_digit($code))) && isset(self::STATUS_CODES[(int) $code])) {
            return self::STATUS_CODES[(int) $code];
        }

        $label = is_string($payload['ctStatus'] ?? null) ? strtolower(trim($payload['ctStatus'])) : '';

        return self::STATUS_LABELS[$label] ?? null;
    }

    /**
     * The phases named in the search result's label ("Phase I and Phase II (Integrated)- Other"),
     * else the phase code of the trial category.
     */
    private function phase(mixed $label, mixed $code): ?string
    {
        if (is_string($label) && preg_match_all('/Phase\s+(IV|III|II|I)\b/', $label, $matches) > 0) {
            $phases = array_values(array_unique(array_map(fn (string $roman): string => self::ROMAN_PHASES[$roman], $matches[1])));
            sort($phases);

            return implode(', ', $phases);
        }

        return is_scalar($code) ? (self::PHASE_CODES[(string) $code] ?? null) : null;
    }

    /**
     * @param  array<string, mixed>  $partI
     * @return array<string, mixed>
     */
    private function primarySponsor(array $partI): array
    {
        $sponsors = $this->list($partI['sponsors'] ?? null);

        foreach ($sponsors as $sponsor) {
            if (($sponsor['primary'] ?? false) === true) {
                return $sponsor;
            }
        }

        return $sponsors[0] ?? [];
    }

    /**
     * @param  list<array<string, mixed>>  $partsII
     */
    private function earliestStart(array $partsII): ?string
    {
        $dates = [];

        foreach ($partsII as $part) {
            $info = $this->array($part['mscInfo'] ?? null);
            $dates[] = $this->date(Arr::get($info, 'activeTrialPeriod.trialStartDate'));

            foreach ($this->list($info['trialPeriod'] ?? null) as $period) {
                $dates[] = $this->date($period['trialStartDate'] ?? null);
            }
        }

        $dates = array_filter($dates);
        sort($dates);

        return $dates[0] ?? null;
    }

    /**
     * Planned participants: the sum of every member state's count and of the rest of the world.
     *
     * @param  array<string, mixed>  $partI
     * @param  list<array<string, mixed>>  $partsII
     */
    private function enrollment(array $partI, array $partsII): ?int
    {
        $counts = array_filter(
            [$partI['rowSubjectCount'] ?? null, ...array_map(fn (array $part): mixed => $part['recruitmentSubjectCount'] ?? null, $partsII)],
            'is_int',
        );

        return $counts === [] || array_sum($counts) === 0 ? null : array_sum($counts);
    }

    /**
     * @param  array<string, mixed>  $criteria
     */
    private function eligibility(array $criteria): ?string
    {
        $sections = [];

        foreach (['Inclusion criteria' => 'principalInclusionCriteria', 'Exclusion criteria' => 'principalExclusionCriteria'] as $heading => $key) {
            $items = array_filter(array_map(
                fn (array $item): ?string => $this->text($item[$key] ?? null),
                $this->list($criteria[$key] ?? null),
            ));

            if ($items !== []) {
                $sections[] = $heading.":\n".implode("\n", $items);
            }
        }

        return $sections === [] ? null : implode("\n\n", $sections);
    }

    /**
     * The EudraCT number of a transitioned trial and every registry identifier listed under
     * additional registries (NCT, ISRCTN...).
     *
     * @param  array<string, mixed>  $application
     * @param  array<string, mixed>  $identifiers
     * @return list<array<string, string|null>>
     */
    private function secondaryIds(array $application, array $identifiers): array
    {
        $pairs = [[Arr::get($application, 'eudraCt.eudraCtCode'), 'EudraCT']];
        $parser = new IdentifierParser;

        // The shape of a filled entry is unverified (empty in every sample), so every value
        // of an entry that parses as a registry identifier is kept.
        foreach ($this->list(Arr::get($identifiers, 'secondaryIdentifyingNumbers.additionalRegistries')) as $entry) {
            foreach (Arr::flatten($entry) as $value) {
                $identifier = is_string($value) ? $parser->parse($value) : null;

                if ($identifier !== null) {
                    $pairs[] = [$value, strtoupper($identifier->scheme->value)];
                }
            }
        }

        return $this->secondaryIdRows($pairs);
    }

    /**
     * @param  array<string, mixed>  $partI
     * @param  array<string, mixed>  $sponsor
     * @return list<array<string, mixed>>
     */
    private function collaborators(array $partI, array $sponsor): array
    {
        $names = [];

        foreach ($this->list($partI['sponsors'] ?? null) as $other) {
            $name = $this->short(Arr::get($other, 'organisation.name'));

            if ($name !== null && $other !== $sponsor) {
                $names[$name] = ['name' => $name, 'class' => ($other['isCommercial'] ?? null) === true ? 'INDUSTRY' : null];
            }
        }

        return array_values($names);
    }

    /**
     * @param  array<string, mixed>  $partI
     * @return list<array<string, string>>
     */
    private function conditions(array $partI): array
    {
        $rows = [];

        foreach ($this->list($partI['medicalConditions'] ?? null) as $condition) {
            $name = $this->short($condition['medicalCondition'] ?? null);

            // Healthy-volunteer trials state "No medical condition."; that is not a condition.
            if ($name !== null && preg_match('/^no medical condition\.?$/i', $name) !== 1) {
                $rows[$name] = ['condition' => $name];
            }
        }

        return array_values($rows);
    }

    /**
     * Therapeutic areas and MedDRA terms.
     *
     * @param  array<string, mixed>  $partI
     * @param  array<string, mixed>  $information
     * @return list<array<string, string>>
     */
    private function keywords(array $partI, array $information): array
    {
        $rows = [];
        $values = [
            ...array_map(fn (array $area): mixed => $area['name'] ?? null, $this->list($partI['therapeuticAreas'] ?? null)),
            ...array_map(fn (array $term): mixed => $term['termName'] ?? null, $this->list(Arr::get($information, 'medicalCondition.meddraConditionTerms'))),
        ];

        foreach ($values as $value) {
            $keyword = $this->short($value);

            if ($keyword !== null && $keyword !== 'Not possible to specify') {
                $rows[$keyword] = ['keyword' => $keyword];
            }
        }

        return array_values($rows);
    }

    /**
     * @param  array<string, mixed>  $partI
     * @return list<array<string, mixed>>
     */
    private function interventions(array $partI): array
    {
        $rows = [];

        foreach ($this->list($partI['products'] ?? null) as $product) {
            $info = $this->array($product['productDictionaryInfo'] ?? null);
            $substance = $this->meaningful($info['activeSubstanceName'] ?? null)
                ?? $this->meaningful($info['activeSubstanceOtherDescriptiveName'] ?? null);
            // Products without a dictionary name are known by their substance or the sponsor's code.
            $name = $this->meaningful($info['prodName'] ?? null) ?? $substance ?? $this->meaningful($info['sponsorProductCode'] ?? null);

            if ($name === null) {
                continue;
            }

            $details = array_filter([
                $substance !== null && $substance !== $name ? "Active substance: {$substance}" : null,
                ($atc = $this->meaningful($info['atcCode'] ?? null)) !== null ? "ATC: {$atc}" : null,
            ]);

            $rows[$name] = ['type' => 'DRUG', 'name' => $name, 'description' => $details === [] ? null : implode('; ', $details)];
        }

        return array_values($rows);
    }

    /**
     * Trial sites of every member state (organisation and address only), or the member state
     * itself when it lists no site.
     *
     * @param  list<array<string, mixed>>  $partsII
     * @return list<array<string, mixed>>
     */
    private function locations(array $partsII): array
    {
        $rows = [];

        foreach ($partsII as $part) {
            $info = $this->array($part['mscInfo'] ?? null);
            $country = $this->short($info['countryName'] ?? $info['mscName'] ?? null);
            $status = $this->short($info['trialStatus'] ?? null);
            $sites = $this->list($part['trialSites'] ?? null);

            foreach ($sites as $site) {
                $address = $this->array(Arr::get($site, 'organisationAddressInfo.address'));
                $rows[] = [
                    'facility' => $this->text(Arr::get($site, 'organisationAddressInfo.organisation.name')),
                    'city' => $this->short($address['city'] ?? null),
                    'state' => null,
                    'country' => $this->short($address['countryName'] ?? null) ?? $country,
                    'status' => $status,
                    'zip' => $this->short($address['postcode'] ?? null),
                    'latitude' => null,
                    'longitude' => null,
                ];
            }

            if ($sites === [] && $country !== null) {
                $rows[] = ['facility' => null, 'city' => null, 'state' => null, 'country' => $country, 'status' => $status, 'zip' => null, 'latitude' => null, 'longitude' => null];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $endPoints
     * @return list<array<string, mixed>>
     */
    private function outcomes(array $endPoints): array
    {
        $rows = [];

        foreach (['PRIMARY' => 'primaryEndPoints', 'SECONDARY' => 'secondaryEndPoints'] as $type => $key) {
            foreach ($this->list($endPoints[$key] ?? null) as $endPoint) {
                $measure = $this->text($endPoint['endPoint'] ?? null);

                if ($measure !== null) {
                    $rows[] = ['type' => $type, 'measure' => $measure, 'description' => null, 'time_frame' => null];
                }
            }
        }

        return $rows;
    }

    /** Text other than the "N/A" placeholder CTIS uses for missing product data. */
    private function meaningful(mixed $value): ?string
    {
        $value = $this->text($value);

        return $value === null || strtoupper($value) === 'N/A' ? null : $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /**
     * @param  array<mixed>  $node
     * @return array<mixed>
     */
    private function withoutPersonalData(array $node): array
    {
        foreach ($node as $key => $value) {
            if (is_string($key) && in_array($key, self::PERSONAL_KEYS, true)) {
                unset($node[$key]);
            } elseif (is_array($value)) {
                $node[$key] = $this->withoutPersonalData($value);
            }
        }

        return $node;
    }
}
