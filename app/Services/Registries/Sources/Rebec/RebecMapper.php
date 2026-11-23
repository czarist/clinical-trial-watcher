<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Rebec;

use App\Enums\StudyPhase;
use App\Enums\StudyStatus;
use App\Enums\StudyType;
use App\Exceptions\InvalidStudyPayload;
use App\Services\ClinicalTrials\Import\MappedStudy;
use App\Services\ClinicalTrials\Import\RawDocument;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\Registries\Parsing\HtmlDocument;
use App\Services\Registries\Sources\MapsRegistryRecords;
use DOMNode;

/**
 * Maps a ReBEC trial page to the common study model. The structured data embedded in the page
 * (the schema.org JSON-LD block and the commented ICTRP PageMap) is preferred; the rest is read
 * from the labelled HTML blocks, choosing English, then Portuguese, then Spanish text.
 *
 * Contacts (names, addresses, phones, emails) are never mapped and are removed from the stored
 * page.
 */
final class RebecMapper implements StudyPayloadMapper
{
    use MapsRegistryRecords;

    private const array LANGUAGES = ['en', 'pt-br', 'es'];

    private const string MAIN = "//div[contains(@class, 'mainContent')]";

    /**
     * Recruitment status codes and labels (PageMap codes, English and Portuguese labels).
     * "Recruitment completed" means the trial goes on without enrolling.
     */
    private const array STATUSES = [
        'not yet recruiting' => StudyStatus::NotYetRecruiting,
        'ainda nao recrutando' => StudyStatus::NotYetRecruiting,
        'recruiting' => StudyStatus::Recruiting,
        'recrutando' => StudyStatus::Recruiting,
        'suspended' => StudyStatus::Suspended,
        'suspenso' => StudyStatus::Suspended,
        'recruitment completed' => StudyStatus::ActiveNotRecruiting,
        'recrutamento concluido' => StudyStatus::ActiveNotRecruiting,
        'data analysis completed' => StudyStatus::Completed,
        'analise de dados concluida' => StudyStatus::Completed,
        'withdrawn' => StudyStatus::Withdrawn,
        'retirado' => StudyStatus::Withdrawn,
        'terminated' => StudyStatus::Terminated,
        'interrompido' => StudyStatus::Terminated,
    ];

    private const array AGE_UNITS = ['Y' => 'Years', 'M' => 'Months', 'W' => 'Weeks', 'D' => 'Days', 'H' => 'Hours'];

    public function payloadId(array $payload): ?string
    {
        return is_string($payload['id'] ?? null) ? $payload['id'] : null;
    }

    public function map(array $payload): MappedStudy
    {
        $html = $payload['html'] ?? null;

        if (! is_string($html) || trim($html) === '') {
            throw new InvalidStudyPayload('The ReBEC payload has no trial page.');
        }

        $page = new HtmlDocument($html);
        $pageMap = $this->pageMap($page);
        $jsonLd = $this->jsonLd($page);
        $heading = $page->text(self::MAIN.'//h2[1]');
        $id = RebecSource::canonicalId($pageMap['trial_id'][0] ?? null)
            ?? RebecSource::canonicalId(is_string($jsonLd['identifier'] ?? null) ? $jsonLd['identifier'] : null)
            ?? RebecSource::canonicalId(explode(' ', (string) $heading)[0])
            ?? RebecSource::canonicalId(is_string($payload['id'] ?? null) ? $payload['id'] : null);

        if ($id === null) {
            throw new InvalidStudyPayload('The ReBEC trial page has no RBR identifier.');
        }

        $title = $this->short($pageMap['public_title_en'][0] ?? null)
            ?? $this->short(is_string($jsonLd['name'] ?? null) ? $jsonLd['name'] : null)
            ?? $this->short($this->first($this->localized($page, 'Public title')))
            ?? $this->short($pageMap['public_title_pt'][0] ?? null)
            ?? $this->short(trim(substr((string) $heading, strlen($id))));

        if ($title === null) {
            throw new InvalidStudyPayload("The ReBEC trial {$id} has no title.");
        }

        $sponsor = $this->short($pageMap['primary_sponsor'][0] ?? null)
            ?? $this->short(is_array($jsonLd['sponsor'] ?? null) ? ($jsonLd['sponsor']['name'] ?? null) : null)
            ?? $this->value($page, 'Primary sponsor');
        $status = $this->status($pageMap['recruitment_status_code'][0] ?? $this->value($page, 'Study status'));
        $recruitment = $this->tableValues($page, "//table[.//th[starts-with(normalize-space(.), 'Target sample size')]]");
        $design = $this->tableValues($page, "//table[.//th[normalize-space(.) = 'Study phase']]");
        $startDate = $this->usDate($this->value($page, 'Date first enrollment'));
        $locations = $this->locations($page, $jsonLd);
        $raw = self::withoutPersonalData($html);
        $url = is_string($payload['url'] ?? null) ? $payload['url'] : RebecSource::BASE_URL.'/rg/'.$id;

        return new MappedStudy(
            source: RebecSource::KEY,
            sourceId: $id,
            attributes: [
                'brief_title' => $title,
                'official_title' => $this->short($this->first($this->localized($page, 'Scientific title', heading: true))),
                'acronym' => $this->short($this->first($this->localized($page, 'Public acronym')) ?? $this->first($this->localized($page, 'Scientific acronym'))),
                'org_study_id' => null,
                'overall_status' => $status,
                'status_verified_date' => null,
                'start_date' => $startDate,
                'primary_completion_date' => null,
                'completion_date' => null,
                'study_first_post_date' => $this->date($pageMap['date_registration'][0] ?? null)
                    ?? $this->usDate($this->value($page, 'Date of registration'))
                    ?? $this->date($payload['registered_on'] ?? null),
                'last_update_post_date' => $this->usDate($this->value($page, 'Last approval date'))
                    ?? $this->date($payload['approved_on'] ?? null),
                'brief_summary' => null,
                'detailed_description' => $this->text($this->first($this->localized($page, 'Study design'))),
                'study_type' => $this->studyType($pageMap['study_type_code'][0] ?? $page->text(self::MAIN."//h3[normalize-space(.) = 'Study type:']/following-sibling::p[1]")),
                'phase' => $this->phase($design['Study phase'] ?? null),
                'enrollment_count' => $this->count($pageMap['target_size'][0] ?? $recruitment['Target sample size'] ?? null),
                'enrollment_type' => null,
                'sponsor_name' => $sponsor,
                'sponsor_class' => null,
                'eligibility_criteria' => $this->eligibility($page),
                'min_age' => $this->age($recruitment['Minimum age'] ?? null),
                'max_age' => $this->age($recruitment['Maximum age'] ?? null),
                'gender' => $this->gender($pageMap['gender'][0] ?? $recruitment['Gender'] ?? null),
                'has_results' => false,
                'raw_json' => [
                    'json_ld' => $jsonLd,
                    'page_map' => $pageMap,
                    'revision' => is_int($payload['revision'] ?? null) ? $payload['revision'] : null,
                ],
            ],
            relations: [
                ...$this->emptyRelations(),
                'secondaryIds' => $this->secondaryIds($page, $pageMap),
                'collaborators' => $this->collaborators($page),
                'conditions' => $this->conditions($page, $pageMap, $jsonLd),
                'keywords' => $this->rows('keyword', $this->descriptors($page, 'Descriptors')),
                'locations' => $locations,
                'interventions' => $this->interventions($page),
                'outcomes' => [...$this->outcomes($page, 'Primary outcomes', 'PRIMARY'), ...$this->outcomes($page, 'Secondary outcomes', 'SECONDARY')],
            ],
            facts: $this->facts($status, $locations, ['start_date' => $startDate]),
            raw: new RawDocument('html', $raw, $url),
        );
    }

    /**
     * The page without its contacts section and without any name, address, phone or email
     * marked up as contact details, so personal data is never stored.
     */
    public static function withoutPersonalData(string $html): string
    {
        $html = (string) preg_replace('#(<h3>\s*Contacts\s*</h3>).*?(?=<h3[\s>]|\z)#si', '$1', $html);
        $html = (string) preg_replace(
            '#(<(span|a)\b[^>]*class="[^"]*\b(?:fn|tel|email|street-address|postal-code)\b[^"]*"[^>]*>).*?(</\2>)#si',
            '$1Redacted$3',
            $html,
        );
        $html = (string) preg_replace('#mailto:[^"\'\s>]+#i', 'mailto:redacted', $html);

        return (string) preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', 'redacted@example.invalid', $html);
    }

    /**
     * Attributes of the commented Google PageMap ("ictrp_trial"); repeated names keep every value.
     *
     * @return array<string, list<string>>
     */
    private function pageMap(HtmlDocument $page): array
    {
        $attributes = [];

        foreach ($page->nodes("//comment()[contains(., 'ictrp_trial')]") as $comment) {
            preg_match_all('#<Attribute\s+name="([^"]+)"\s*>(.*?)</Attribute>#s', self::decoded($comment->textContent), $matches, PREG_SET_ORDER);

            foreach ($matches as [, $name, $value]) {
                $value = $this->short(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 2000);

                if ($value !== null) {
                    $attributes[$name][] = $value;
                }
            }
        }

        return $attributes;
    }

    /**
     * The schema.org MedicalTrial JSON-LD block, or an empty array.
     *
     * @return array<string, mixed>
     */
    private function jsonLd(HtmlDocument $page): array
    {
        foreach ($page->nodes("//script[@type='application/ld+json']") as $script) {
            $data = json_decode(self::decoded($script->textContent), true);

            if (is_array($data) && ($data['@type'] ?? null) === 'MedicalTrial') {
                return $data;
            }
        }

        return [];
    }

    /**
     * Comments and scripts are not decoded by the HTML parser, so the non-ASCII characters
     * HtmlDocument turned into numeric entities are restored here.
     */
    private static function decoded(string $text): string
    {
        return mb_decode_numericentity($text, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
    }

    /** The value span that follows a label span, e.g. "Study status:" → "Recruiting". */
    private function value(HtmlDocument $page, string $label): ?string
    {
        return $this->short($page->text(self::MAIN."//span[contains(@class, 'label')][starts-with(normalize-space(.), '{$label}')]/following-sibling::span[contains(@class, 'value')][1]"));
    }

    /**
     * Texts of a multilingual block, one map of language => text per row (outcomes have one row
     * per outcome). The block is either a list item with a label or, with $heading, an h3.
     *
     * @return list<array<string, string>>
     */
    private function localized(HtmlDocument $page, string $label, bool $heading = false): array
    {
        $rows = $heading
            ? self::MAIN."//h3[starts-with(normalize-space(.), '{$label}')]/following-sibling::div[contains(@class, 'row')][1]"
            : self::MAIN."//li[p/span[contains(@class, 'label')][starts-with(normalize-space(.), '{$label}')]]/div[contains(@class, 'row')]";
        $result = [];

        foreach ($page->nodes($rows) as $row) {
            $texts = [];

            foreach ($page->nodes(".//div[contains(@class, 'balloon')]", $row) as $balloon) {
                $language = strtolower((string) $page->text('./h2', $balloon));
                $text = $page->text(".//span[contains(@class, 'value')]", $balloon) ?? $page->text('./p', $balloon);

                if ($language !== '' && $text !== null) {
                    $texts[$language] ??= $text;
                }
            }

            if ($texts !== []) {
                $result[] = $texts;
            }
        }

        return $result;
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function first(array $rows): ?string
    {
        return $rows === [] ? null : $this->preferred($rows[0]);
    }

    /**
     * @param  array<string, string>  $texts
     */
    private function preferred(array $texts): ?string
    {
        foreach (self::LANGUAGES as $language) {
            if (isset($texts[$language])) {
                return $texts[$language];
            }
        }

        return $texts === [] ? null : reset($texts);
    }

    /**
     * Values of a table whose first row holds the labels and whose next row holds the values.
     *
     * @return array<string, string>
     */
    private function tableValues(HtmlDocument $page, string $table): array
    {
        $node = $page->nodes(self::MAIN.$table)[0] ?? null;

        if (! $node instanceof DOMNode) {
            return [];
        }

        $labels = array_map(fn (DOMNode $th): string => rtrim((string) $page->text('.', $th), ': '), $page->nodes('.//tr[th][1]/th', $node));
        $values = array_map(fn (DOMNode $td): ?string => $page->text('.', $td), $page->nodes('.//tr[td][1]/td', $node));
        $result = [];

        foreach ($labels as $index => $label) {
            $value = $values[$index] ?? null;

            if ($label !== '' && $value !== null && $value !== '-') {
                $result[$label] = $value;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, list<string>>  $pageMap
     * @return list<array<string, string|null>>
     */
    private function secondaryIds(HtmlDocument $page, array $pageMap): array
    {
        $pairs = [[$this->value($page, 'UTN code') ?? ($pageMap['utrn'][0] ?? null), 'UTN']];

        foreach ($page->nodes(self::MAIN."//li[span[contains(@class, 'legend')][starts-with(normalize-space(.), 'Secondaries identifiers') or starts-with(normalize-space(.), 'Secondary identifiers')]]//ul/li") as $item) {
            $id = $page->text("./span[contains(@class, 'label')]", $item);
            $authority = $page->texts("./span[contains(@class, 'value')]", $item)[1] ?? null;
            // Ethics approval numbers of Plataforma Brasil (CAAE): 17 digits, often dotted.
            $isCaae = preg_match('/^\d{8}\.?\d\.?\d{4}\.?\d{4}$/', (string) $id) === 1
                && preg_match('/plataforma\s+brasil|caae/i', (string) $authority) === 1;
            $pairs[] = [$id, $isCaae ? 'CAAE' : $authority];
        }

        return $this->secondaryIdRows($pairs);
    }

    /**
     * Secondary sponsors. Supporting sources are left out: they are often people.
     *
     * @return list<array<string, string|null>>
     */
    private function collaborators(HtmlDocument $page): array
    {
        $names = $page->texts(self::MAIN."//li[span[contains(@class, 'legend')][starts-with(normalize-space(.), 'Secondary sponsor')]]//span[contains(@class, 'value')]");

        return array_map(fn (string $name): array => ['name' => $name, 'class' => null], $this->unique($names));
    }

    /**
     * Free-text health conditions and the ICD-10 descriptors.
     *
     * @param  array<string, list<string>>  $pageMap
     * @param  array<string, mixed>  $jsonLd
     * @return list<array<string, string>>
     */
    private function conditions(HtmlDocument $page, array $pageMap, array $jsonLd): array
    {
        $conditions = array_map(fn (array $texts): ?string => $this->preferred($texts), $this->localized($page, 'Health conditions'));
        $conditions = [...$conditions, ...$this->descriptors($page, 'General descriptors'), ...$this->descriptors($page, 'Specific descriptors')];

        if ($conditions === []) {
            $conditions = [...($pageMap['hc_freetext_en'] ?? $pageMap['hc_freetext_pt'] ?? []), ...array_filter((array) ($jsonLd['healthCondition'] ?? []), 'is_string')];
        }

        return $this->rows('condition', $conditions);
    }

    /**
     * Descriptor terms (ICD-10 or DeCS) of a block, in the preferred language.
     *
     * @return list<string>
     */
    private function descriptors(HtmlDocument $page, string $label): array
    {
        $terms = [];

        foreach ($page->nodes(self::MAIN."//li[p/span[contains(@class, 'label')][starts-with(normalize-space(.), '{$label}')]]/div[contains(@class, 'row')]") as $row) {
            $byLanguage = [];

            foreach ($page->nodes(".//div[contains(@class, 'balloon')]", $row) as $balloon) {
                $language = strtolower((string) $page->text('./h2', $balloon));
                $byLanguage[$language] = $page->texts(".//span[contains(@class, 'value')]", $balloon);
            }

            foreach (self::LANGUAGES as $language) {
                if (($byLanguage[$language] ?? []) !== []) {
                    $terms = [...$terms, ...$byLanguage[$language]];

                    break;
                }
            }
        }

        return $terms;
    }

    /**
     * @return list<array<string, string|null>>
     */
    private function interventions(HtmlDocument $page): array
    {
        $rows = [];

        foreach ($this->localized($page, 'Interventions') as $texts) {
            $text = $this->preferred($texts);

            if ($this->short($text) !== null) {
                $rows[] = ['type' => null, 'name' => $this->short($text), 'description' => $this->text($text)];
            }
        }

        return $rows;
    }

    /**
     * @return list<array<string, string|null>>
     */
    private function outcomes(HtmlDocument $page, string $label, string $type): array
    {
        $rows = [];

        foreach ($this->localized($page, $label) as $texts) {
            $text = $this->preferred($texts);

            if ($this->short($text) !== null) {
                $rows[] = ['type' => $type, 'measure' => $this->short($text), 'description' => $this->text($text), 'time_frame' => null];
            }
        }

        return $rows;
    }

    /**
     * Countries only: ReBEC publishes no structured list of sites.
     *
     * @param  array<string, mixed>  $jsonLd
     * @return list<array<string, mixed>>
     */
    private function locations(HtmlDocument $page, array $jsonLd): array
    {
        $countries = $page->texts(self::MAIN."//li[span[contains(@class, 'legend')][normalize-space(.) = 'Countries']]/ul/li");

        if ($countries === []) {
            foreach ((array) ($jsonLd['studyLocation'] ?? []) as $place) {
                if (is_array($place) && is_string($place['name'] ?? null)) {
                    $countries[] = $place['name'];
                }
            }
        }

        return array_map(fn (string $country): array => [
            'facility' => null,
            'city' => null,
            'state' => null,
            'country' => $country,
            'status' => null,
            'zip' => null,
            'latitude' => null,
            'longitude' => null,
        ], $this->unique($countries));
    }

    private function eligibility(HtmlDocument $page): ?string
    {
        $parts = [];

        foreach (['Inclusion criteria', 'Exclusion criteria'] as $label) {
            $text = $this->first($this->localized($page, $label));

            if ($text !== null) {
                $parts[] = "{$label}:\n{$text}";
            }
        }

        return $parts === [] ? null : implode("\n\n", $parts);
    }

    private function status(?string $value): ?string
    {
        $key = $this->normalized($value);

        return $key === null ? null : (self::STATUSES[$key] ?? null)?->value;
    }

    private function studyType(?string $value): ?string
    {
        return match ($this->normalized($value)) {
            'interventional', 'intervencional' => StudyType::Interventional->value,
            'observational', 'observacional' => StudyType::Observational->value,
            'expanded access' => StudyType::ExpandedAccess->value,
            default => null,
        };
    }

    /** "2", "2-3", "Phase 2/3", "N/A" → PHASE2, "PHASE2, PHASE3", NA; phase 0 is early phase 1. */
    private function phase(?string $value): ?string
    {
        $value = $this->normalized($value);

        if ($value === null) {
            return null;
        }

        if (preg_match('#\b(n/?a|not applicable|nao se aplica)\b#', $value) === 1) {
            return StudyPhase::NotApplicable->value;
        }

        preg_match_all('/(?<![a-z0-9])(iv|iii|ii|i|[0-4])(?![a-z0-9])/', str_replace('phase', ' ', $value), $matches);
        $phases = [];

        foreach ($matches[1] as $number) {
            $phases[] = match ($number) {
                '0' => StudyPhase::EarlyPhase1,
                '1', 'i' => StudyPhase::Phase1,
                '2', 'ii' => StudyPhase::Phase2,
                '3', 'iii' => StudyPhase::Phase3,
                default => StudyPhase::Phase4,
            };
        }

        $phases = array_values(array_unique(array_map(fn (StudyPhase $phase): string => $phase->value, $phases)));

        return $phases === [] ? null : implode(', ', $phases);
    }

    private function gender(?string $value): ?string
    {
        return match ($this->normalized($value)) {
            'both', 'ambos', 'all', 'm/f', 'f/m' => 'ALL',
            'female', 'feminino', 'f' => 'FEMALE',
            'male', 'masculino', 'm' => 'MALE',
            default => null,
        };
    }

    /** "18 Y" → "18 Years"; 0 means no limit. */
    private function age(?string $value): ?string
    {
        if ($value === null || preg_match('/^(\d+)\s*([YMWDH])?\b/i', trim($value), $match) !== 1 || (int) $match[1] === 0) {
            return null;
        }

        $unit = isset($match[2]) ? self::AGE_UNITS[strtoupper($match[2])] : null;

        return $unit !== null ? "{$match[1]} {$unit}" : $this->short($value);
    }

    private function count(mixed $value): ?int
    {
        return is_string($value) && preg_match('/^\d+$/', trim($value)) === 1 ? (int) $value : null;
    }

    /** A date shown as mm/dd/yyyy, as YYYY-MM-DD. */
    private function usDate(?string $value): ?string
    {
        if ($value === null || preg_match('#(\d{2})/(\d{2})/(\d{4})#', $value, $match) !== 1 || ! checkdate((int) $match[1], (int) $match[2], (int) $match[3])) {
            return null;
        }

        return "{$match[3]}-{$match[1]}-{$match[2]}";
    }

    /** Lower case without accents, underscores or hyphens, for matching vocabularies. */
    private function normalized(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtolower(strtr($value, ['á' => 'a', 'ã' => 'a', 'â' => 'a', 'à' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ú' => 'u', 'ç' => 'c', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ç' => 'c']));
        $value = trim((string) preg_replace('/[\s_-]+/', ' ', $value));

        return $value === '' ? null : $value;
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
