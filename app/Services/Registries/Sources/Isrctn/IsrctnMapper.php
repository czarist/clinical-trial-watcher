<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Isrctn;

use App\Enums\StudyPhase;
use App\Enums\StudyStatus;
use App\Exceptions\InvalidStudyPayload;
use App\Services\ClinicalTrials\Import\MappedStudy;
use App\Services\ClinicalTrials\Import\RawDocument;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\Registries\Sources\MapsRegistryRecords;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Maps one ISRCTN <fullTrial> (API "default" format) to the common study model.
 *
 * The payload is ['xml' => the <fullTrial> document, 'url' => where it was read]. ISRCTN
 * stores no overall status: like its website, the status is derived from the recruitment
 * and overall end dates, unless an editor set a recruitment status override. Contact
 * persons are dropped, and e-mail addresses and phone numbers are removed from free text
 * and from the stored raw record.
 */
final class IsrctnMapper implements StudyPayloadMapper
{
    use MapsRegistryRecords;

    /** Values ISRCTN uses for an empty identifier field ("Nil known", "N/A"...) carry no digit. */
    private const string HAS_DIGIT = '/\d/';

    private const array OVERRIDE_STATUSES = [
        'stopped' => StudyStatus::Terminated,
        'suspended' => StudyStatus::Suspended,
        'enrollingbyinvitation' => StudyStatus::EnrollingByInvitation,
        'notyetrecruiting' => StudyStatus::NotYetRecruiting,
        'recruiting' => StudyStatus::Recruiting,
        'completed' => StudyStatus::Completed,
    ];

    private const array INTERVENTION_TYPES = [
        'drug' => 'DRUG',
        'behavioural' => 'BEHAVIORAL',
        'behavioral' => 'BEHAVIORAL',
        'device' => 'DEVICE',
        'biological' => 'BIOLOGICAL',
        'biologic' => 'BIOLOGICAL',
        'vaccine' => 'BIOLOGICAL',
        'procedure/surgery' => 'PROCEDURE',
        'procedure' => 'PROCEDURE',
        'supplement' => 'DIETARY_SUPPLEMENT',
        'genetic' => 'GENETIC',
        'radiation' => 'RADIATION',
        'other' => 'OTHER',
    ];

    private const array ELEMENTS_WITH_PERSONAL_DATA = ['email', 'telephone', 'forename', 'surname', 'orcid'];

    /** The record's ISRCTN number read from its XML without parsing the whole document. */
    public static function idFromXml(string $xml): ?string
    {
        if (preg_match('/<isrctn\b[^>]*>\s*(?:ISRCTN)?(\d{8})\s*<\/isrctn>/i', $xml, $match) === 1
            || preg_match('/publicIdentifierCanonical="ISRCTN(\d{8})"/', $xml, $match) === 1) {
            return 'ISRCTN'.$match[1];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[\Override]
    public function payloadId(array $payload): ?string
    {
        return is_string($payload['xml'] ?? null) ? self::idFromXml($payload['xml']) : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidStudyPayload
     */
    #[\Override]
    public function map(array $payload): MappedStudy
    {
        $xml = $payload['xml'] ?? null;
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = is_string($xml) && trim($xml) !== '' && $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->documentElement;

        if (! $loaded || $root === null || $root->localName !== 'fullTrial' || $root->namespaceURI !== IsrctnClient::XML_NAMESPACE) {
            throw new InvalidStudyPayload('The ISRCTN payload is not a <fullTrial> record.');
        }

        $x = new DOMXPath($document);
        $x->registerNamespace('i', IsrctnClient::XML_NAMESPACE);

        $digits = $this->value($x, '/i:fullTrial/i:trial/i:isrctn');
        $digits = $digits !== null ? preg_replace('/^ISRCTN/i', '', $digits) : null;

        if ($digits === null || preg_match('/^\d{8}$/', $digits) !== 1) {
            throw new InvalidStudyPayload('The ISRCTN record has no ISRCTN number.');
        }

        $sourceId = 'ISRCTN'.$digits;
        $title = $this->short($this->value($x, '//i:trialDescription/i:title'))
            ?? $this->short($this->value($x, '//i:trialDescription/i:scientificTitle'));

        if ($title === null) {
            throw new InvalidStudyPayload("The ISRCTN record {$sourceId} has no title.");
        }

        $today = CarbonImmutable::now('UTC')->toDateString();
        $recruitmentStart = $this->date($this->value($x, '//i:participants/i:recruitmentStart'));
        $recruitmentEnd = $this->date($this->value($x, '//i:participants/i:recruitmentEnd'));
        $startDate = $this->date($this->value($x, '//i:trialDesign/i:overallStartDate')) ?? $recruitmentStart;
        $completionDate = $this->date($this->value($x, '//i:trialDesign/i:overallEndDate'));
        $status = $this->status($x, $today, $recruitmentStart, $recruitmentEnd, $completionDate);
        $hasResults = $this->value($x, '//i:results/i:basicReport') !== null
            || (int) $x->evaluate('count(//i:outputs/i:output[contains(@outputType, "basicresults")])') > 0;
        $locations = $this->locations($x);
        [$enrollment, $enrollmentType] = $this->enrollment($x, $today, $recruitmentEnd);
        $sponsors = $this->values($x, '/i:fullTrial/i:sponsor/i:organisation');

        $relations = $this->emptyRelations();
        $relations['secondaryIds'] = $this->secondaryIds($x);
        $relations['collaborators'] = $this->collaborators($x, $sponsors);
        $relations['references'] = $this->references($x);
        $relations['conditions'] = $this->rows($this->values($x, '//i:conditions/i:condition/i:description'), 'condition');
        $relations['locations'] = $locations;
        $relations['interventions'] = $this->interventions($x);
        $relations['outcomes'] = $this->outcomes($x);

        return new MappedStudy(
            source: IsrctnSource::KEY,
            sourceId: $sourceId,
            attributes: [
                'brief_title' => $title,
                'official_title' => $this->short($this->value($x, '//i:trialDescription/i:scientificTitle')),
                'acronym' => $this->short($this->value($x, '//i:trialDescription/i:acronym')),
                'org_study_id' => $this->short($this->value($x, '//i:externalRefs/i:protocolSerialNumber')),
                'overall_status' => $status?->value,
                'status_verified_date' => null,
                'start_date' => $startDate,
                'primary_completion_date' => null,
                'completion_date' => $completionDate,
                'study_first_post_date' => $this->date($x->evaluate('string(/i:fullTrial/i:trial/i:isrctn/@dateAssigned)')),
                'last_update_post_date' => $this->date($x->evaluate('string(/i:fullTrial/i:trial/@lastUpdated)')),
                'has_expanded_access' => false,
                'brief_summary' => $this->clean($this->value($x, '//i:trialDescription/i:plainEnglishSummary')),
                'detailed_description' => $this->clean($this->value($x, '//i:trialDescription/i:studyHypothesis')),
                'study_type' => match (strtolower((string) $this->value($x, '//i:trialDesign/i:primaryStudyDesign'))) {
                    'interventional' => 'INTERVENTIONAL',
                    'observational' => 'OBSERVATIONAL',
                    default => null,
                },
                'phase' => $this->phase($this->values($x, '//i:interventions/i:intervention/i:phase')),
                'enrollment_count' => $enrollment,
                'enrollment_type' => $enrollmentType,
                'sponsor_name' => $this->short($sponsors[0] ?? null),
                'sponsor_class' => match (strtolower((string) $this->value($x, '/i:fullTrial/i:sponsor[1]/i:sponsorType'))) {
                    '' => null,
                    'industry' => 'INDUSTRY',
                    'government' => 'OTHER_GOV',
                    default => 'OTHER',
                },
                'responsible_party_type' => null,
                'eligibility_criteria' => $this->eligibility($x),
                'min_age' => $this->age($x, 'lowerAgeLimit'),
                'max_age' => $this->age($x, 'upperAgeLimit'),
                'gender' => match (strtolower((string) $this->value($x, '//i:participants/i:gender'))) {
                    'all', 'both' => 'ALL',
                    'female' => 'FEMALE',
                    'male' => 'MALE',
                    default => null,
                },
                'is_fda_regulated_drug' => false,
                'is_fda_regulated_device' => false,
                'ipd_sharing' => match (strtolower((string) $this->value($x, '//i:miscellaneous/i:ipdSharingPlan'))) {
                    'yes' => 'YES',
                    'no' => 'NO',
                    default => null,
                },
                'has_results' => $hasResults,
            ],
            relations: $relations,
            facts: $this->facts($status?->value, $locations, [
                'start_date' => $startDate,
                'primary_completion_date' => null,
                'completion_date' => $completionDate,
            ], $hasResults),
            raw: new RawDocument('xml', $this->rawWithoutPersonalData($document), is_string($payload['url'] ?? null) ? $payload['url'] : null),
        );
    }

    /**
     * An editor's recruitment status override wins; otherwise the status follows the dates,
     * as on the ISRCTN website. Null when there is no recruitment start date.
     */
    private function status(DOMXPath $x, string $today, ?string $recruitmentStart, ?string $recruitmentEnd, ?string $overallEnd): ?StudyStatus
    {
        $override = strtolower((string) preg_replace('/[^a-z]/i', '', (string) $this->value($x, '//i:participants/i:recruitmentStatusOverride')));

        if (isset(self::OVERRIDE_STATUSES[$override])) {
            return self::OVERRIDE_STATUSES[$override];
        }

        return match (true) {
            $recruitmentStart === null => null,
            $today < $recruitmentStart => StudyStatus::NotYetRecruiting,
            $recruitmentEnd === null || $today <= $recruitmentEnd => StudyStatus::Recruiting,
            $overallEnd === null || $today > $overallEnd => StudyStatus::Completed,
            default => StudyStatus::ActiveNotRecruiting,
        };
    }

    /**
     * The final enrolment once recruitment has ended (ISRCTN fills it with 0 before), else the target.
     *
     * @return array{0: int|null, 1: string|null}
     */
    private function enrollment(DOMXPath $x, string $today, ?string $recruitmentEnd): array
    {
        $final = $this->value($x, '//i:participants/i:totalFinalEnrolment');

        if ($final !== null && ctype_digit($final) && $recruitmentEnd !== null && $today > $recruitmentEnd) {
            return [(int) $final, 'ACTUAL'];
        }

        $target = $this->value($x, '//i:participants/i:targetEnrolment');

        return $target !== null && ctype_digit($target) ? [(int) $target, 'ESTIMATED'] : [null, null];
    }

    /**
     * @param  list<string>  $values
     */
    private function phase(array $values): ?string
    {
        $phases = [];
        $romans = ['I' => StudyPhase::Phase1, 'II' => StudyPhase::Phase2, 'III' => StudyPhase::Phase3, 'IV' => StudyPhase::Phase4,
            '1' => StudyPhase::Phase1, '2' => StudyPhase::Phase2, '3' => StudyPhase::Phase3, '4' => StudyPhase::Phase4];

        foreach ($values as $value) {
            if (preg_match('/not\s+applicable/i', $value) === 1) {
                $phases[StudyPhase::NotApplicable->value] = StudyPhase::NotApplicable;
            } elseif (preg_match('/phase\s*(IV|III|II|I|[1-4])(?:\s*\/\s*(?:phase\s*)?(IV|III|II|I|[1-4]))?/i', $value, $match) === 1) {
                foreach (array_slice($match, 1) as $number) {
                    $phase = $romans[strtoupper($number)] ?? null;

                    if ($phase !== null) {
                        $phases[$phase->value] = $phase;
                    }
                }
            }
        }

        if (count($phases) > 1) {
            unset($phases[StudyPhase::NotApplicable->value]);
        }

        $order = array_map(fn (StudyPhase $phase): string => $phase->value, StudyPhase::cases());
        $phases = array_keys($phases);
        usort($phases, fn (string $a, string $b): int => array_search($a, $order, true) <=> array_search($b, $order, true));

        return $phases === [] ? null : implode(', ', $phases);
    }

    /**
     * Every registry number the record cites; sponsor protocol serial numbers are left out.
     *
     * @return list<array<string, string|null>>
     */
    private function secondaryIds(DOMXPath $x): array
    {
        $ids = [
            [$this->value($x, '//i:externalRefs/i:clinicalTrialsGovNumber'), 'NCT'],
            [$this->value($x, '//i:externalRefs/i:eudraCTNumber'), 'EudraCT'],
            [$this->value($x, '//i:externalRefs/i:irasNumber'), 'IRAS'],
        ];

        foreach ($x->query('//i:externalRefs/i:secondaryNumbers/i:secondaryNumber') ?: [] as $node) {
            $type = $node instanceof DOMElement ? $this->short($node->getAttribute('numberType')) : null;

            if ($type === null || stripos($type, 'protocol serial') === false) {
                $ids[] = [$this->text($node->textContent), $type];
            }
        }

        // The first mention of a number keeps its type (the dedicated fields come first).
        $unique = [];

        foreach ($ids as $pair) {
            if ($pair[0] !== null && preg_match(self::HAS_DIGIT, $pair[0]) === 1) {
                $unique[strtoupper((string) $this->short($pair[0]))] ??= $pair;
            }
        }

        return $this->secondaryIdRows($unique);
    }

    /**
     * Further sponsors and the funders.
     *
     * @param  list<string>  $sponsors
     * @return list<array<string, string|null>>
     */
    private function collaborators(DOMXPath $x, array $sponsors): array
    {
        $rows = [];

        foreach ([...array_slice($sponsors, 1), ...$this->values($x, '/i:fullTrial/i:funder/i:name')] as $name) {
            $name = $this->short($name);

            if ($name !== null && $name !== $this->short($sponsors[0] ?? null)) {
                $rows[$name] = ['name' => $name, 'class' => null];
            }
        }

        return array_values($rows);
    }

    /**
     * @return list<array<string, string|null>>
     */
    private function references(DOMXPath $x): array
    {
        $rows = [];

        foreach ($x->query('//i:outputs/i:output[i:externalLink/@url]') ?: [] as $output) {
            $url = $this->short($x->evaluate('string(i:externalLink/@url)', $output));

            if ($url === null || strlen($url) > 250 || isset($rows[$url])) {
                continue;
            }

            $rows[$url] = [
                'pmid' => preg_match('#pubmed(?:\.ncbi\.nlm\.nih\.gov)?/(\d+)#', $url, $match) === 1 ? $match[1] : null,
                'type' => $output instanceof DOMElement ? $this->short($output->getAttribute('outputType')) : null,
                'citation' => $this->clean($this->value($x, 'i:description', $output)),
                'url' => $url,
            ];
        }

        return array_values($rows);
    }

    /**
     * Trial centres, then recruitment countries that no centre covers.
     *
     * @return list<array<string, mixed>>
     */
    private function locations(DOMXPath $x): array
    {
        $rows = [];
        $countries = [];

        foreach ($x->query('//i:participants/i:trialCentres/i:trialCentre') ?: [] as $centre) {
            $row = [
                'facility' => $this->short($this->value($x, 'i:name', $centre)),
                'city' => $this->short($this->value($x, 'i:city', $centre)),
                'state' => $this->short($this->value($x, 'i:state', $centre)),
                'country' => $this->short($this->value($x, 'i:country', $centre)),
                'status' => null,
                'zip' => $this->short($this->value($x, 'i:zip', $centre)),
                'latitude' => null,
                'longitude' => null,
            ];

            if ($row['facility'] !== null || $row['city'] !== null || $row['country'] !== null) {
                $rows[] = $row;
                $countries[strtolower((string) $row['country'])] = true;
            }
        }

        foreach ($this->values($x, '//i:participants/i:recruitmentCountries/i:country') as $country) {
            $country = $this->short($country);

            if ($country !== null && ! isset($countries[strtolower($country)])) {
                $countries[strtolower($country)] = true;
                $rows[] = ['facility' => null, 'city' => null, 'state' => null, 'country' => $country,
                    'status' => null, 'zip' => null, 'latitude' => null, 'longitude' => null];
            }
        }

        return $rows;
    }

    /**
     * @return list<array<string, string|null>>
     */
    private function interventions(DOMXPath $x): array
    {
        $rows = [];

        foreach ($x->query('//i:interventions/i:intervention') ?: [] as $intervention) {
            $description = $this->clean($this->value($x, 'i:description', $intervention));
            $firstLine = $description !== null ? strtok($description, "\n") : null;
            $name = $this->short($this->value($x, 'i:drugNames', $intervention)) ?? $this->short($firstLine === false ? null : $firstLine);

            if ($name === null) {
                continue;
            }

            $type = strtolower((string) $this->value($x, 'i:interventionType', $intervention));
            $rows[] = [
                'type' => self::INTERVENTION_TYPES[$type] ?? $this->short($type === '' ? null : strtoupper($type)),
                'name' => $name,
                'description' => $description,
            ];
        }

        return $rows;
    }

    /**
     * Structured outcome measures (newer records) or the free-text outcome (older records).
     *
     * @return list<array<string, string|null>>
     */
    private function outcomes(DOMXPath $x): array
    {
        $rows = [];

        foreach (['PRIMARY' => 'primary', 'SECONDARY' => 'secondary'] as $type => $prefix) {
            foreach ($x->query("//i:trialDescription/i:{$prefix}Outcomes/i:outcomeMeasure") ?: [] as $measure) {
                $variable = $this->short($this->value($x, 'i:variable', $measure));

                if ($variable !== null) {
                    $rows[] = [
                        'type' => $type,
                        'measure' => $variable,
                        'description' => $this->clean($this->value($x, 'i:method', $measure)),
                        'time_frame' => $this->short($this->value($x, 'i:timepoints', $measure)),
                    ];
                }
            }

            $text = $this->clean($this->value($x, "//i:trialDescription/i:{$prefix}Outcome"));

            if ($text !== null && preg_match('/^(none|not provided.*|n\/?a)\.?$/i', $text) !== 1) {
                $rows[] = ['type' => $type, 'measure' => $this->short($text), 'description' => $text, 'time_frame' => null];
            }
        }

        return $rows;
    }

    private function eligibility(DOMXPath $x): ?string
    {
        $parts = array_filter([
            'Inclusion criteria' => $this->clean($this->value($x, '//i:participants/i:inclusion')),
            'Exclusion criteria' => $this->clean($this->value($x, '//i:participants/i:exclusion')),
        ]);

        if ($parts === []) {
            return null;
        }

        return implode("\n\n", array_map(fn (string $label, string $text): string => "{$label}:\n{$text}", array_keys($parts), $parts));
    }

    /** An age limit as "18 Years", from the element's text or its value and unit. */
    private function age(DOMXPath $x, string $element): ?string
    {
        $text = $this->short($this->value($x, "//i:participants/i:{$element}"), 50);

        if ($text !== null && preg_match(self::HAS_DIGIT, $text) === 1) {
            return $text;
        }

        $value = $x->evaluate("string(//i:participants/i:{$element}/@value)");
        $unit = $x->evaluate("string(//i:participants/i:{$element}/@unit)");

        if (! is_numeric($value) || ! is_string($unit) || trim($unit) === '') {
            return null;
        }

        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.').' '.ucfirst(strtolower(trim($unit)));
    }

    /** The stored copy of the record: contact persons and e-mail or phone elements removed. */
    private function rawWithoutPersonalData(DOMDocument $document): string
    {
        $copy = clone $document;
        $x = new DOMXPath($copy);
        $x->registerNamespace('i', IsrctnClient::XML_NAMESPACE);
        $names = implode(' or ', array_map(fn (string $name): string => "local-name()='{$name}'", self::ELEMENTS_WITH_PERSONAL_DATA));

        foreach ([...($x->query('/i:fullTrial/i:contact') ?: []), ...($x->query("//*[{$names}]") ?: [])] as $node) {
            $node->parentNode?->removeChild($node);
        }

        return self::redact((string) $copy->saveXML($copy->documentElement));
    }

    /** Long text with e-mail addresses and phone numbers removed, or null when not provided. */
    private function clean(?string $value): ?string
    {
        $value = $this->text($value);

        if ($value === null || preg_match('/^not provided( at time of registration)?\.?$/i', $value) === 1) {
            return null;
        }

        return self::redact($value);
    }

    private static function redact(string $value): string
    {
        $value = (string) preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email removed]', $value);

        return (string) preg_replace('/(?<![\w\/.-])\+\d[\d\s().-]{6,}\d/', '[phone removed]', $value);
    }

    private function value(DOMXPath $x, string $query, ?DOMNode $context = null): ?string
    {
        $nodes = $x->query($query, $context);
        $node = $nodes === false ? null : $nodes->item(0);

        return $node !== null ? $this->text($node->textContent) : null;
    }

    /**
     * @return list<string>
     */
    private function values(DOMXPath $x, string $query): array
    {
        $values = [];

        foreach ($x->query($query) ?: [] as $node) {
            $value = $this->text($node->textContent);

            if ($value !== null) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @param  list<string>  $values
     * @return list<array<string, string>>
     */
    private function rows(array $values, string $column): array
    {
        $rows = [];

        foreach ($values as $value) {
            $value = $this->short($value);

            if ($value !== null) {
                $rows[strtolower($value)] = [$column => $value];
            }
        }

        return array_values($rows);
    }
}
