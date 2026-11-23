<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Hra;

use App\Exceptions\InvalidStudyPayload;
use App\Services\ClinicalTrials\Import\MappedStudy;
use App\Services\ClinicalTrials\Import\RawDocument;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\Registries\Parsing\HtmlDocument;
use App\Services\Registries\Sources\MapsRegistryRecords;
use DOMNode;

/**
 * Maps an HRA research summary page to the common study model. The page is a list of labelled
 * values (h4 label, p or div value). Status, phase, conditions, interventions, sites, dates and
 * enrolment are not published as structured data, so they stay empty; the REC review (name,
 * reference, opinion and its date) is kept in raw_json, not presented as a trial status.
 *
 * The IRAS number is the record's identifier and is also listed as a secondary identifier so
 * ISRCTN and ClinicalTrials.gov records citing it are linked. Contact names and emails are
 * never mapped and are removed from the stored page.
 */
final class HraMapper implements StudyPayloadMapper
{
    use MapsRegistryRecords;

    private const string ITEMS = "//div[contains(@class, 'research-summary-list')]//li[contains(@class, 'research-summary-list__item')][h4]";

    public function payloadId(array $payload): ?string
    {
        return is_string($payload['id'] ?? null) ? $payload['id'] : null;
    }

    public function map(array $payload): MappedStudy
    {
        $html = $payload['html'] ?? null;

        if (! is_string($html) || trim($html) === '') {
            throw new InvalidStudyPayload('The HRA payload has no research summary page.');
        }

        $page = new HtmlDocument((string) preg_replace('#<br\s*/?>#i', "\n", $html));
        $values = $this->values($page);
        $id = HraSource::canonicalId($values['IRAS ID'] ?? null)
            ?? HraSource::canonicalId(is_string($payload['id'] ?? null) ? $payload['id'] : null);

        if ($id === null) {
            throw new InvalidStudyPayload('The HRA research summary has no IRAS ID.');
        }

        $title = $this->short($page->text("//div[contains(@class, 'research-summary-list')]/h1"))
            ?? $this->short($values['Full title'] ?? null);

        if ($title === null) {
            throw new InvalidStudyPayload("The HRA research summary {$id} has no title.");
        }

        $recReference = $this->short($values['REC reference'] ?? null);
        $url = is_string($payload['url'] ?? null) ? $payload['url'] : null;

        return new MappedStudy(
            source: HraSource::KEY,
            sourceId: $id,
            attributes: [
                'brief_title' => $title,
                'official_title' => $this->short($values['Full title'] ?? null),
                'acronym' => null,
                'org_study_id' => null,
                'overall_status' => null,
                'status_verified_date' => null,
                'start_date' => null,
                'primary_completion_date' => null,
                'completion_date' => null,
                'study_first_post_date' => null,
                'last_update_post_date' => null,
                'brief_summary' => $this->text($values['Research summary'] ?? null),
                'detailed_description' => null,
                'study_type' => null,
                'phase' => null,
                'enrollment_count' => null,
                'enrollment_type' => null,
                'sponsor_name' => $this->short($values['Sponsor organisation'] ?? null),
                'sponsor_class' => null,
                'eligibility_criteria' => null,
                'min_age' => null,
                'max_age' => null,
                'gender' => null,
                'has_results' => false,
                'raw_json' => [
                    'research_type' => $this->short($values['Research type'] ?? null),
                    'rec_name' => $this->short($values['REC name'] ?? null),
                    'rec_reference' => $recReference,
                    'rec_opinion' => $this->short($values['REC opinion'] ?? null),
                    'rec_opinion_date' => $this->date($values['Date of REC Opinion'] ?? null, 'j M Y')
                        ?? $this->date($payload['rec_opinion_date'] ?? null),
                ],
            ],
            relations: [
                ...$this->emptyRelations(),
                'secondaryIds' => $this->secondaryIdRows([[$id, 'IRAS'], [$recReference, 'REC reference']]),
            ],
            facts: $this->facts(null, [], []),
            raw: new RawDocument('html', self::withoutPersonalData($html), $url),
        );
    }

    /**
     * The page without contact details: every "Contact ..." item is emptied, and mailto links
     * and email addresses are replaced, so personal data is never stored.
     */
    public static function withoutPersonalData(string $html): string
    {
        $html = (string) preg_replace(
            '#(<li\b[^>]*research-summary-list__item[^>]*>\s*<h4>\s*Contact[^<]*</h4>).*?(</li>)#si',
            '$1<p>Redacted</p>$2',
            $html,
        );
        $html = (string) preg_replace('#mailto:[^"\'\s>]+#i', 'mailto:redacted', $html);

        return (string) preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', 'redacted@example.invalid', $html);
    }

    /**
     * Labelled values keyed by label, contacts left out. Line breaks are kept in long values.
     *
     * @return array<string, string>
     */
    private function values(HtmlDocument $page): array
    {
        $values = [];

        foreach ($page->nodes(self::ITEMS) as $item) {
            $label = $page->text('./h4', $item);
            $value = $page->nodes('./h4/following-sibling::*[1]', $item)[0] ?? null;

            if ($label === null || str_starts_with(strtolower($label), 'contact') || ! $value instanceof DOMNode) {
                continue;
            }

            $text = $this->multiline($value->textContent);

            if ($text !== null) {
                $values[$label] ??= $text;
            }
        }

        return $values;
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
}
