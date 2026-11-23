<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\PpbKenya;

use App\Enums\StudyPhase;
use App\Enums\StudyStatus;
use App\Enums\StudyType;
use App\Exceptions\InvalidStudyPayload;
use App\Services\ClinicalTrials\Import\MappedStudy;
use App\Services\ClinicalTrials\Import\RawDocument;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\Registries\Parsing\HtmlDocument;
use App\Services\Registries\Sources\MapsRegistryRecords;

/**
 * Maps a PPB Kenya record page, with the sites and sponsor code read from its list row, to the
 * common study model. The registry publishes few structured fields: no sponsor, conditions,
 * interventions, eligibility or other registry's identifiers. Investigators are never mapped.
 */
final class PpbKenyaMapper implements StudyPayloadMapper
{
    use MapsRegistryRecords;

    public function payloadId(array $payload): ?string
    {
        $protocol = $this->short($payload['protocol_no'] ?? null, 100);

        return $protocol ?? (is_int($payload['view_id'] ?? null) ? 'view-'.$payload['view_id'] : null);
    }

    public function map(array $payload): MappedStudy
    {
        $html = $payload['html'] ?? null;

        if (! is_string($html) || trim($html) === '') {
            throw new InvalidStudyPayload('The PPB Kenya payload has no record page.');
        }

        $page = new HtmlDocument($html);
        $values = $this->values($page);
        $viewId = is_int($payload['view_id'] ?? null) && $payload['view_id'] > 0 ? $payload['view_id'] : null;
        $sourceId = $this->short($values['Protocol No'] ?? null, 100)
            ?? $this->short($payload['protocol_no'] ?? null, 100)
            ?? ($viewId !== null ? 'view-'.$viewId : null);

        if ($sourceId === null) {
            throw new InvalidStudyPayload('The PPB Kenya record has no protocol number or page number.');
        }

        $title = $this->text($values['Study Title'] ?? null) ?? $this->text($payload['title'] ?? null);

        if ($title === null) {
            throw new InvalidStudyPayload("The PPB Kenya record {$sourceId} has no title.");
        }

        $status = $this->status($values['Status'] ?? (is_string($payload['list_status'] ?? null) ? $payload['list_status'] : null));
        $locations = $this->locations($payload['sites'] ?? []);
        $url = is_string($payload['url'] ?? null) ? $payload['url'] : ($viewId !== null ? PpbKenyaSource::viewUrl($viewId) : null);

        return new MappedStudy(
            source: PpbKenyaSource::KEY,
            sourceId: $sourceId,
            attributes: [
                'brief_title' => $this->short($title),
                'official_title' => null,
                'acronym' => null,
                // The sponsor's own code; IdentifierParser ignores values that are not registry identifiers.
                'org_study_id' => $this->short($payload['code'] ?? null),
                'overall_status' => $status,
                'status_verified_date' => null,
                'start_date' => null,
                'primary_completion_date' => null,
                'completion_date' => null,
                'study_first_post_date' => $this->date($values['Date Approved'] ?? null, 'd-m-Y') ?? $this->date($payload['approved_on'] ?? null),
                'last_update_post_date' => null,
                'brief_summary' => $this->text($values['Laymans Summary'] ?? null),
                'detailed_description' => $this->description($values),
                // The board approves clinical trials of medicines, vaccines and devices.
                'study_type' => StudyType::Interventional->value,
                'phase' => $this->phase($title),
                'enrollment_count' => null,
                'enrollment_type' => null,
                'sponsor_name' => null,
                'sponsor_class' => null,
                'eligibility_criteria' => null,
                'min_age' => null,
                'max_age' => null,
                'gender' => null,
                'has_results' => false,
                'raw_json' => [
                    'view_id' => $viewId,
                    'protocol_no' => $this->short($payload['protocol_no'] ?? null),
                    'code' => $this->short($payload['code'] ?? null),
                    'list_status' => is_string($payload['list_status'] ?? null) ? $payload['list_status'] : null,
                    'date_of_protocol' => $this->date($values['Date of Protocol'] ?? null, 'd-m-Y'),
                    'approved_on' => $this->date($values['Date Approved'] ?? null, 'd-m-Y') ?? $this->date($payload['approved_on'] ?? null),
                    'sites' => $locations === [] ? [] : array_map(fn (array $row): array => ['facility' => $row['facility'], 'county' => $row['state']], $locations),
                ],
            ],
            relations: [...$this->emptyRelations(), 'locations' => $locations],
            facts: $this->facts($status, $locations, []),
            raw: new RawDocument('html', $html, $url),
        );
    }

    /**
     * Label and value cells of the record ("Protocol No:" followed by its value).
     *
     * @return array<string, string>
     */
    private function values(HtmlDocument $page): array
    {
        $values = [];
        $labels = $page->nodes("//div[@id='applicationPrintArea']//td[following-sibling::td][substring(normalize-space(.), string-length(normalize-space(.))) = ':']");

        foreach ($labels as $label) {
            $name = rtrim((string) $page->text('.', $label), ': ');
            $value = $page->text('following-sibling::td[1]', $label);

            if ($name !== '' && $value !== null) {
                $values[$name] ??= $value;
            }
        }

        return $values;
    }

    /**
     * @param  array<string, string>  $values
     */
    private function description(array $values): ?string
    {
        $parts = [];

        foreach (['Study Objectives' => 'Study objectives', 'Abstract of Study' => 'Abstract'] as $label => $heading) {
            if (($text = $this->text($values[$label] ?? null)) !== null) {
                $parts[] = "{$heading}:\n{$text}";
            }
        }

        return $parts === [] ? null : implode("\n\n", $parts);
    }

    /**
     * Approved is a regulatory decision, not a recruitment status, so it has no equivalent.
     * Stopped trials will not resume; suspended ones may.
     */
    private function status(?string $value): ?string
    {
        return match (strtolower(trim((string) $value))) {
            'stopped' => StudyStatus::Terminated->value,
            'suspended' => StudyStatus::Suspended->value,
            default => null,
        };
    }

    /**
     * Phases stated in the title ("A Phase 1, ...", "Phase IIb", "Phase 2/3"); the registry has
     * no phase field.
     */
    private function phase(string $title): ?string
    {
        if (preg_match('/\bphase\s*(iv|iii|ii|i|[0-4])[ab]?(?![a-z0-9])(?:\s*(?:\/|-|and|&)\s*(?:phase\s*)?(iv|iii|ii|i|[0-4])[ab]?(?![a-z0-9]))?/i', $title, $match) !== 1) {
            return null;
        }

        $phases = [];

        foreach (array_filter(array_slice($match, 1), 'strlen') as $number) {
            $phases[] = match (strtolower($number)) {
                '0' => StudyPhase::EarlyPhase1->value,
                '1', 'i' => StudyPhase::Phase1->value,
                '2', 'ii' => StudyPhase::Phase2->value,
                '3', 'iii' => StudyPhase::Phase3->value,
                default => StudyPhase::Phase4->value,
            };
        }

        return implode(', ', array_unique($phases));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function locations(mixed $sites): array
    {
        $rows = [];

        foreach (is_array($sites) ? $sites : [] as $site) {
            $facility = is_array($site) ? $this->short($site['name'] ?? null) : null;

            if ($facility === null) {
                continue;
            }

            $county = $this->short($site['county'] ?? null);
            $rows[mb_strtolower($facility.'|'.$county)] = [
                'facility' => $facility,
                'city' => null,
                'state' => $county,
                'country' => 'Kenya',
                'status' => null,
                'zip' => null,
                'latitude' => null,
                'longitude' => null,
            ];
        }

        return array_values($rows);
    }
}
