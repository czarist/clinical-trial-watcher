<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Orphanet;

use App\Enums\IdentifierScheme;
use App\Enums\StudyStatus;
use App\Exceptions\InvalidStudyPayload;
use App\Services\ClinicalTrials\Import\MappedStudy;
use App\Services\ClinicalTrials\Import\RawDocument;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\Registries\Identifiers\IdentifierParser;
use App\Services\Registries\Sources\MapsRegistryRecords;
use DOMDocument;
use DOMElement;
use DOMXPath;
use SimpleXMLElement;

/**
 * Maps one `ClinicalTrial` element of an Orphadata clinical trials dump. Rare diseases are
 * imported as conditions and as "ORPHA:<code>" keywords; NCT, EU CT, EudraCT, ISRCTN and DRKS
 * numbers found in the registry links and the detail text become secondary identifiers.
 *
 * People are never imported: a sponsor is named only when it is an organisation (a person
 * entry without a first name), sites come from the institution and its address, and person
 * entries with a first name, and any contact element, are removed from the kept raw record.
 */
final class OrphanetMapper implements StudyPayloadMapper
{
    use MapsRegistryRecords;

    private const string SPONSOR_FUNCTION = 'sponsor of clinical trial';

    /** Orphanet phase labels; combined phases are written with a slash or a hyphen. */
    private const array PHASES = ['I' => 'PHASE1', 'II' => 'PHASE2', 'III' => 'PHASE3', 'IV' => 'PHASE4'];

    /** Elements dropped from the raw record wherever they appear (contact details). */
    private const array CONTACT_ELEMENTS = ['Email', 'Mail', 'Phone', 'Telephone', 'Fax'];

    /** Identifier candidates of the registries Orphanet links to, checked with IdentifierParser. */
    private const array IDENTIFIER_PATTERNS = [
        'NCT' => '/\bNCT\d{8}\b/i',
        'EU CT number' => '/\b(?:19|20)\d{2}-5\d{5}-\d{2}-\d{2}\b/',
        'EudraCT' => '/\b(?:19|20)\d{2}-\d{6}-\d{2}\b(?!-\d)/',
        'ISRCTN' => '/\bISRCTN\d{8}\b/i',
        'DRKS' => '/\bDRKS\d{8}\b/i',
    ];

    public function payloadId(array $payload): ?string
    {
        $id = $payload['id'] ?? null;

        if (! is_string($id) && is_string($payload['xml'] ?? null) && preg_match('/^\s*<ClinicalTrial\b[^>]*\bid="([^"]*)"/', $payload['xml'], $match) === 1) {
            $id = $match[1];
        }

        return is_string($id) && preg_match('/^\d{1,20}$/', trim($id)) === 1 ? trim($id) : null;
    }

    public function map(array $payload): MappedStudy
    {
        $trial = is_string($payload['xml'] ?? null) ? $this->load($payload['xml']) : null;
        $id = $trial !== null ? $this->payloadId(['id' => (string) $trial['id']]) : null;

        if ($trial === null || $id === null) {
            throw new InvalidStudyPayload('The Orphanet clinical trial has no id.');
        }

        $status = strtolower((string) $this->value($trial, 'Recruitment/Name')) === 'yes' ? StudyStatus::Recruiting : null;

        $dates = [
            'start_date' => $this->date($this->value($trial, 'BeginDate')),
            'primary_completion_date' => null,
            'completion_date' => $this->date($this->value($trial, 'EndDate')),
        ];

        $nameEn = $this->value($trial, 'Name_en');
        $name = $this->value($trial, 'Name');
        $associations = $trial->xpath('PersonActivityAssociationList/PersonActivityAssociation') ?: [];

        $attributes = [
            'brief_title' => $this->short($nameEn ?? $name) ?? $id,
            // The title in its original language, when an English one is given.
            'official_title' => $nameEn !== null && $name !== null && $name !== $nameEn ? $name : null,
            'acronym' => null,
            'org_study_id' => null,
            'overall_status' => $status?->value,
            'status_verified_date' => null,
            ...$dates,
            'study_first_post_date' => null,
            // The date Orphanet last validated the record.
            'last_update_post_date' => $this->date($this->value($trial, 'ValidationDate')),
            'has_expanded_access' => false,
            'brief_summary' => null,
            'detailed_description' => $this->detail($this->value($trial, 'Detail')),
            'study_type' => $this->studyType($trial),
            'phase' => $this->phase($this->value($trial, 'Phase/Name')),
            'enrollment_count' => null,
            'enrollment_type' => null,
            'sponsor_name' => $this->sponsor($associations),
            'sponsor_class' => null,
            'responsible_party_type' => null,
            'eligibility_criteria' => null,
            'min_age' => null,
            'max_age' => null,
            'gender' => null,
            'is_fda_regulated_drug' => false,
            'is_fda_regulated_device' => false,
            'ipd_sharing' => null,
            'has_results' => false,
        ];

        $relations = $this->emptyRelations();
        $relations['secondaryIds'] = $this->secondaryIds($trial);
        [$relations['conditions'], $relations['keywords']] = $this->disorders($trial);
        $relations['interventions'] = $this->interventions($trial);
        $relations['locations'] = $this->locations($associations);

        $expertLink = $this->value($trial, 'ExpertLink');

        return new MappedStudy(
            source: OrphanetSource::KEY,
            sourceId: $id,
            attributes: $attributes,
            relations: $relations,
            facts: $this->facts($status?->value, $relations['locations'], $dates),
            raw: new RawDocument(
                'xml',
                $this->withoutPersonalData($payload['xml']),
                $expertLink !== null && preg_match('#^https?://#i', $expertLink) === 1 ? $expertLink : null,
            ),
        );
    }

    /**
     * Registry identifiers cited in the links and the detail text, validated by the parser.
     *
     * @return list<array<string, string|null>>
     */
    public function identifiersIn(string ...$texts): array
    {
        $parser = new IdentifierParser;
        $pairs = [];

        foreach ($texts as $text) {
            foreach (self::IDENTIFIER_PATTERNS as $type => $pattern) {
                preg_match_all($pattern, $text, $matches);

                foreach ($matches[0] as $candidate) {
                    $identifier = $parser->parse($candidate);

                    if ($identifier !== null) {
                        $pairs[] = [$identifier->value, $identifier->scheme === IdentifierScheme::EuCt ? 'EU CT number' : $type];
                    }
                }
            }
        }

        return $this->secondaryIdRows($pairs);
    }

    private function load(string $xml): ?SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $element = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);

            return $element instanceof SimpleXMLElement && $element->getName() === 'ClinicalTrial' ? $element : null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** Trimmed text of the first node at the path, or null when absent or empty. */
    private function value(SimpleXMLElement $node, string $path): ?string
    {
        $found = $node->xpath($path);

        return $found !== false && $found !== [] ? $this->text((string) $found[0]) : null;
    }

    /**
     * @return list<array<string, string|null>>
     */
    private function secondaryIds(SimpleXMLElement $trial): array
    {
        $texts = array_filter(array_map(
            fn (string $path): ?string => $this->value($trial, $path),
            ['Url', 'ProtocolUrl', 'PSOIEUrl', 'Detail'],
        ));

        return $this->identifiersIn(...array_values($texts));
    }

    private function studyType(SimpleXMLElement $trial): ?string
    {
        foreach ($trial->xpath('ClinicalTrialTypeFlagList/ClinicalTrialTypeFlag/Name') ?: [] as $flag) {
            if (stripos((string) $flag, 'clinical trial') !== false) {
                return 'INTERVENTIONAL';
            }
        }

        return null;
    }

    private function phase(?string $label): ?string
    {
        if ($label === null) {
            return null;
        }

        $phases = [];

        foreach (preg_split('/\s*[\/\-]\s*/', strtoupper($label)) ?: [] as $part) {
            if (! isset(self::PHASES[$part])) {
                return null; // an unknown label is not guessed
            }

            $phases[] = self::PHASES[$part];
        }

        return $phases === [] ? null : implode(', ', array_unique($phases));
    }

    /**
     * The first sponsor that is an organisation: a person entry without a first name, else the
     * institution's department or hosting institution.
     *
     * @param  list<SimpleXMLElement>  $associations
     */
    private function sponsor(array $associations): ?string
    {
        foreach ($associations as $association) {
            if (! $this->isSponsor($association)) {
                continue;
            }

            $organisation = $this->value($association, 'Person/Firstname') === null ? $this->value($association, 'Person/Lastname') : null;
            $name = $organisation
                ?? $this->value($association, 'Institution/Department_Service_Lab_PatientOrganisationName')
                ?? $this->value($association, 'Institution/Address/Hosting_Institution');

            if ($name !== null) {
                return $this->short($name);
            }
        }

        return null;
    }

    private function isSponsor(SimpleXMLElement $association): bool
    {
        return strtolower((string) $this->value($association, 'PersonFunction/Name')) === self::SPONSOR_FUNCTION;
    }

    /**
     * Sites of the investigators: institution, town and country of their address.
     *
     * @param  list<SimpleXMLElement>  $associations
     * @return list<array<string, mixed>>
     */
    private function locations(array $associations): array
    {
        $rows = [];

        foreach ($associations as $association) {
            if ($this->isSponsor($association)) {
                continue;
            }

            $facility = $this->value($association, 'Institution/Address/Hosting_Institution')
                ?? $this->value($association, 'Institution/Department_Service_Lab_PatientOrganisationName');
            $city = $this->titleCase($this->value($association, 'Institution/Address/Town/Name'));

            if ($facility === null && $city === null) {
                continue;
            }

            $rows[$facility.'|'.$city] = [
                'facility' => $facility,
                'city' => $this->short($city),
                'state' => null,
                'country' => $this->short($this->titleCase($this->value($association, 'Institution/Address/Country/Name'))),
                'status' => null,
                'zip' => null,
                'latitude' => null,
                'longitude' => null,
            ];
        }

        return array_values($rows);
    }

    /**
     * Conditions (disorder names) and keywords (their ORPHAcodes).
     *
     * @return array{0: list<array<string, string>>, 1: list<array<string, string>>}
     */
    private function disorders(SimpleXMLElement $trial): array
    {
        $conditions = [];
        $keywords = [];

        foreach ($trial->xpath('DisorderList/Disorder') ?: [] as $disorder) {
            $name = $this->short($this->value($disorder, 'Name'));
            $code = $this->value($disorder, 'OrphaCode');

            if ($name !== null) {
                $conditions[$name] = ['condition' => $name];
            }

            if ($code !== null && ctype_digit($code)) {
                $keywords[$code] = ['keyword' => 'ORPHA:'.$code];
            }
        }

        return [array_values($conditions), array_values($keywords)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function interventions(SimpleXMLElement $trial): array
    {
        $rows = [];

        foreach ([
            'SubstanceClinicalTrialAssociationList/SubstanceClinicalTrialAssociation/Substance/Name' => null,
            'DrugTradeNameClinicalTrialAssociationList/DrugTradeNameClinicalTrialAssociation/DrugTradeName/Name' => 'Trade name',
        ] as $path => $description) {
            foreach ($trial->xpath($path) ?: [] as $node) {
                $name = $this->short((string) $node);

                if ($name !== null && ! isset($rows[$name])) {
                    $rows[$name] = ['type' => 'DRUG', 'name' => $name, 'description' => $description];
                }
            }
        }

        return array_values($rows);
    }

    /** The detail text, unless it only repeats identifiers. */
    private function detail(?string $detail): ?string
    {
        if ($detail === null) {
            return null;
        }

        $rest = preg_replace(array_values(self::IDENTIFIER_PATTERNS), '', $detail) ?? $detail;
        $rest = preg_replace('/\b(?:EU\s*CT|EudraCT|number|no)\b|[^\p{L}\p{N}]+/iu', '', $rest) ?? $rest;

        return $rest === '' ? null : $detail;
    }

    private function titleCase(?string $value): ?string
    {
        return $value === null ? null : mb_convert_case(mb_strtolower($value), MB_CASE_TITLE);
    }

    /** The element without person entries that name an individual and without contact elements. */
    private function withoutPersonalData(string $xml): string
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;

        try {
            if (! $document->loadXML($xml, LIBXML_NONET)) {
                return '';
            }

            $xpath = new DOMXPath($document);
            $contacts = implode(' | ', array_map(fn (string $name): string => '//'.$name, self::CONTACT_ELEMENTS));
            $nodes = [
                ...iterator_to_array($xpath->query('//Person[normalize-space(Firstname) != ""]') ?: []),
                ...iterator_to_array($xpath->query($contacts) ?: []),
            ];

            foreach ($nodes as $node) {
                if ($node instanceof DOMElement && $node->parentNode !== null) {
                    $node->parentNode->removeChild($node);
                }
            }

            return (string) $document->saveXML($document->documentElement);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
