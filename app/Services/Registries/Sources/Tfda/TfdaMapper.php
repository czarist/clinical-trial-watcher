<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Tfda;

use App\Exceptions\InvalidStudyPayload;
use App\Services\ClinicalTrials\Import\MappedStudy;
use App\Services\ClinicalTrials\Import\RawDocument;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\Registries\Sources\MapsRegistryRecords;

/**
 * Maps one TFDA trial (the export rows sharing a TFDA document number) to the common study
 * model. The row updated last (資料更新時間) gives the trial's values; conditions are collected
 * from every row. Text stays in Chinese as published.
 *
 * The export has no trial-level status, so overall_status is null; it has no column for other
 * registries' identifiers either, so no secondary identifiers are imported (identifiers are
 * not scraped from free text). The sponsor protocol number goes to org_study_id only. The
 * value "0" is the export's placeholder for a missing value.
 */
final class TfdaMapper implements StudyPayloadMapper
{
    use MapsRegistryRecords;

    private const array PHASES = ['Ⅰ' => 'PHASE1', 'Ⅱ' => 'PHASE2', 'Ⅲ' => 'PHASE3', 'Ⅳ' => 'PHASE4', 'IV' => 'PHASE4', 'III' => 'PHASE3', 'II' => 'PHASE2', 'I' => 'PHASE1'];

    #[\Override]
    public function payloadId(array $payload): ?string
    {
        return $this->short($payload['document_number'] ?? null);
    }

    #[\Override]
    public function map(array $payload): MappedStudy
    {
        $id = $this->payloadId($payload);
        $rows = array_values(array_filter(is_array($payload['rows'] ?? null) ? $payload['rows'] : [], 'is_array'));

        if ($id === null || $rows === []) {
            throw new InvalidStudyPayload('The TFDA record has no TFDA document number (TFDA收文號).');
        }

        $row = $this->latestRow($rows);
        $dates = [
            'start_date' => $this->date($this->value($row, '試驗預計執行期間起'), 'Y/m/d'),
            'primary_completion_date' => null,
            'completion_date' => $this->date($this->value($row, '試驗預計執行期間迄'), 'Y/m/d'),
        ];

        $relations = $this->emptyRelations();
        $relations['conditions'] = $this->conditions($rows);
        $relations['locations'] = [['facility' => null, 'city' => null, 'state' => null, 'country' => 'Taiwan', 'status' => null, 'zip' => null, 'latitude' => null, 'longitude' => null]];
        $outcome = $this->text($this->value($row, '主要評估指標'));
        $relations['outcomes'] = $outcome === null ? [] : [[
            'type' => 'PRIMARY',
            'measure' => $this->short($outcome),
            'description' => $outcome,
            'time_frame' => null,
        ]];

        $title = $this->text($this->value($row, '臨床試驗計畫中文名稱'));
        $enrollment = $this->value($row, '全球預計受試者人數');

        return new MappedStudy(
            source: TfdaSource::KEY,
            sourceId: $id,
            attributes: [
                'brief_title' => $title,
                'official_title' => $title,
                'org_study_id' => $this->short($this->value($row, '臨床試驗計畫書編號')),
                'overall_status' => null,
                'start_date' => $dates['start_date'],
                'primary_completion_date' => null,
                'completion_date' => $dates['completion_date'],
                'last_update_post_date' => $this->date($this->value($row, '資料更新時間'), 'Y/m/d'),
                'brief_summary' => $this->text($this->value($row, '試驗目的')),
                'study_type' => 'INTERVENTIONAL',
                'phase' => $this->phase($this->value($row, '臨床試驗期別')),
                'enrollment_count' => $enrollment !== null && ctype_digit($enrollment) ? (int) $enrollment : null,
                'enrollment_type' => $enrollment !== null && ctype_digit($enrollment) ? 'ESTIMATED' : null,
                'sponsor_name' => $this->short($this->value($row, '臨床試驗申請者')),
                'eligibility_criteria' => $this->eligibility($row),
            ],
            relations: $relations,
            facts: $this->facts(null, $relations['locations'], $dates),
            raw: new RawDocument('csv', $this->csv($payload['header'] ?? null, $rows), is_string($payload['url'] ?? null) ? $payload['url'] : null),
        );
    }

    /**
     * Phases are written "Phase Ⅱ,Phase Ⅲ" with Roman numeral characters; "其他" (other) and
     * "0" have no phase.
     */
    private function phase(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $phases = [];

        foreach (preg_split('/[,，、\/]+/u', $value) ?: [] as $part) {
            if (preg_match('/^\s*Phase\s*(Ⅰ|Ⅱ|Ⅲ|Ⅳ|IV|III|II|I)\s*$/iu', $part, $match) === 1) {
                $phases[] = self::PHASES[strtoupper($match[1])] ?? self::PHASES[$match[1]];
            }
        }

        $phases = array_values(array_unique($phases));
        sort($phases);

        return $phases === [] ? null : implode(', ', $phases);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{condition: string}>
     */
    private function conditions(array $rows): array
    {
        $conditions = [];

        foreach ($rows as $row) {
            $condition = $this->short($this->value($row, '適應症中文'));

            if ($condition !== null) {
                $conditions[$condition] = ['condition' => $condition];
            }
        }

        return array_values($conditions);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function eligibility(array $row): ?string
    {
        $parts = array_filter([
            ($inclusion = $this->text($this->value($row, '納入條件'))) !== null ? "納入條件：\n{$inclusion}" : null,
            ($exclusion = $this->text($this->value($row, '排除條件'))) !== null ? "排除條件：\n{$exclusion}" : null,
        ]);

        return $parts === [] ? null : implode("\n\n", $parts);
    }

    /**
     * The row updated last; on equal dates, the last one in the export.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function latestRow(array $rows): array
    {
        $latest = $rows[0];

        foreach ($rows as $row) {
            if ((string) $this->date($this->value($row, '資料更新時間'), 'Y/m/d') >= (string) $this->date($this->value($latest, '資料更新時間'), 'Y/m/d')) {
                $latest = $row;
            }
        }

        return $latest;
    }

    /**
     * A cell, or null when empty or the export's "0" placeholder.
     *
     * @param  array<string, mixed>  $row
     */
    private function value(array $row, string $column): ?string
    {
        $value = $row[$column] ?? null;

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim(str_replace("\r\n", "\n", (string) $value));

        return $value === '' || $value === '0' ? null : $value;
    }

    /**
     * The trial's rows as CSV with the export's header, as published.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function csv(mixed $header, array $rows): string
    {
        $header = is_array($header) && $header !== [] ? array_values(array_map('strval', $header)) : array_keys($rows[0]);
        $handle = fopen('php://temp', 'w+b');
        assert($handle !== false);
        fputcsv($handle, $header, ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($handle, array_map(static fn (string $column): string => is_scalar($row[$column] ?? null) ? (string) $row[$column] : '', $header), ',', '"', '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
