<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\MozUkraine;

use App\Exceptions\InvalidStudyPayload;
use App\Services\ClinicalTrials\Import\MappedStudy;
use App\Services\ClinicalTrials\Import\RawDocument;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\Registries\Sources\MapsRegistryRecords;

/**
 * Maps one annex of a MOZ order (the authorisation of a clinical trial) to the common study
 * model. Text stays in Ukrainian as published.
 *
 * The record id is the protocol code written in the title cell ("…», код дослідження
 * D8890C00001, версія 1.0 від …"): orders carry no registry identifier. The code identifies the
 * record within this source only; it is never used to link registrations of other registries,
 * and the annexes cite no registry identifier, so no secondary identifiers are imported.
 *
 * An authorisation has no recruitment status (overall_status is null); the order date is kept
 * as study_first_post_date. Investigators' names are dropped from the sites: only the
 * institution and its city are kept.
 */
final class MozUkraineMapper implements StudyPayloadMapper
{
    use MapsRegistryRecords;

    private const string CODE = '/»\s*,?\s*(?:код\s+(?:дослідження|випробування|протоколу)\s*:?\s*)?([^,«»]{2,80}?)\s*,\s*(?:версія|version)\b/iu';

    #[\Override]
    public function payloadId(array $payload): ?string
    {
        $title = $this->joined($payload, 'title');

        if ($title === null || preg_match(self::CODE, $title, $match) !== 1) {
            return null;
        }

        return $this->short(preg_replace('/\s+/u', '', $match[1]));
    }

    #[\Override]
    public function map(array $payload): MappedStudy
    {
        $id = $this->payloadId($payload);

        if ($id === null) {
            throw new InvalidStudyPayload('The MOZ annex has no protocol code in its trial title.');
        }

        $cell = (string) $this->joined($payload, 'title');
        preg_match(self::CODE, $cell, $match, PREG_OFFSET_CAPTURE);
        // The title is the quoted text before the code: drop the opening quote.
        $title = $this->text(preg_replace('/^\s*«\s*/u', '', substr($cell, 0, (int) $match[0][1])));

        $relations = $this->emptyRelations();
        $relations['locations'] = $this->sites($this->lines($payload, 'sites'));
        $relations['interventions'] = [
            ...$this->products($this->joined($payload, 'imps'), 'DRUG'),
            ...$this->products($this->joined($payload, 'comparators'), 'DRUG'),
        ];

        $applicant = $this->short($this->joined($payload, 'applicant'));
        $relations['collaborators'] = $applicant === null ? [] : [['name' => $applicant, 'class' => null]];
        $dates = ['start_date' => null, 'primary_completion_date' => null, 'completion_date' => null];

        return new MappedStudy(
            source: MozUkraineSource::KEY,
            sourceId: $id,
            attributes: [
                'brief_title' => $title,
                'official_title' => $title,
                'org_study_id' => $id,
                'overall_status' => null,
                'study_first_post_date' => $this->date($payload['order_date'] ?? null),
                'study_type' => 'INTERVENTIONAL',
                'phase' => $this->phase($title),
                'sponsor_name' => $this->short($this->joined($payload, 'sponsor')),
            ],
            relations: $relations,
            facts: $this->facts(null, $relations['locations'], $dates),
            raw: new RawDocument('pdf', $this->rawText($payload), is_string($payload['url'] ?? null) ? $payload['url'] : null),
        );
    }

    /** Phase written in the title: "дослідження III фази", "фази 3", "фази 1b/2a". */
    private function phase(?string $title): ?string
    {
        if ($title === null) {
            return null;
        }

        $numeral = '(?:IV|III|II|I|ІV|ІІІ|ІІ|І|[1-4])[abаб]?';

        if (preg_match("/фаз[аиі]\\s+({$numeral}(?:\\s*[\\/–-]\\s*{$numeral})?)(?![\\w])/u", $title, $match) !== 1
            && preg_match("/(?<![\\w])({$numeral}(?:\\s*[\\/–-]\\s*{$numeral})?)\\s+фаз[аиі]/u", $title, $match) !== 1) {
            return null;
        }

        $phases = [];

        foreach (preg_split('/\s*[\/–-]\s*/u', $match[1]) ?: [] as $part) {
            $part = strtr((string) preg_replace('/[abаб]$/u', '', $part), ['І' => 'I']);
            $phase = match ($part) {
                'I', '1' => 'PHASE1',
                'II', '2' => 'PHASE2',
                'III', '3' => 'PHASE3',
                'IV', '4' => 'PHASE4',
                default => null,
            };

            if ($phase !== null) {
                $phases[$phase] = $phase;
            }
        }

        sort($phases);

        return $phases === [] ? null : implode(', ', $phases);
    }

    /**
     * Numbered sites: "1) <investigator>" followed by the institution, ending with ", м. <city>".
     * The investigator's name is dropped.
     *
     * @param  list<string>  $lines
     * @return list<array<string, mixed>>
     */
    private function sites(array $lines): array
    {
        $sites = [];
        $current = null;

        foreach ($lines as $line) {
            if (preg_match('/^\d+\)\s*(.*)$/u', $line, $match) === 1) {
                if ($current !== null) {
                    $sites[] = $current;
                }

                // Keep any institution text written after the investigator's initials.
                $rest = preg_match('/[А-ЯІЇЄҐA-Z]\.\s?[А-ЯІЇЄҐA-Z]\.\s*(.*)$/u', $match[1], $after) === 1 ? $after[1] : '';
                $current = trim($rest);

                continue;
            }

            if ($current !== null) {
                $current = trim($current.(str_ends_with($current, '-') ? '' : ' ').$line);
            }
        }

        if ($current !== null) {
            $sites[] = $current;
        }

        $rows = [];

        foreach ($sites as $site) {
            $city = preg_match('/,\s*м\.\s*([^,]+?)\s*$/u', $site, $match) === 1 ? $match[1] : null;
            $facility = $this->short($city !== null ? preg_replace('/,\s*м\.\s*[^,]+?\s*$/u', '', $site) : $site);

            if ($facility === null) {
                continue;
            }

            $rows[mb_strtolower($facility.'|'.$city)] = [
                'facility' => $facility,
                'city' => $this->short($city),
                'state' => null,
                'country' => 'Ukraine',
                'status' => null,
                'zip' => null,
                'latitude' => null,
                'longitude' => null,
            ];
        }

        return array_values($rows);
    }

    /**
     * Products of the IMP or comparator cell: the name is the first item before ";" outside
     * parentheses, the cell is kept as the description. "―" means none.
     *
     * @return list<array<string, string|null>>
     */
    private function products(?string $cell, string $type): array
    {
        if ($cell === null || preg_match('/^[\s―—–-]*$/u', $cell) === 1) {
            return [];
        }

        $depth = 0;
        $name = '';

        foreach (mb_str_split($cell) as $character) {
            $depth += match ($character) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };

            if ($character === ';' && $depth <= 0) {
                break;
            }

            $name .= $character;
        }

        $name = $this->short($name);

        return $name === null ? [] : [['type' => $type, 'name' => $name, 'description' => $cell]];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function lines(array $payload, string $field): array
    {
        $lines = $payload['fields'][$field] ?? [];

        return is_array($lines) ? array_values(array_filter($lines, static fn (mixed $line): bool => is_string($line) && trim($line) !== '')) : [];
    }

    /**
     * The cell's lines joined, hyphenated line ends rejoined.
     *
     * @param  array<string, mixed>  $payload
     */
    private function joined(array $payload, string $field): ?string
    {
        $text = '';

        foreach ($this->lines($payload, $field) as $line) {
            $text .= ($text === '' || str_ends_with($text, '-') ? '' : ' ').trim($line);
        }

        return $this->text($text);
    }

    /**
     * The annex's cells, one "field: value" block each, investigators' names excluded.
     *
     * @param  array<string, mixed>  $payload
     */
    private function rawText(array $payload): string
    {
        $lines = [
            'order: '.(is_scalar($payload['order_number'] ?? null) ? (string) $payload['order_number'] : '')
                .' of '.(is_scalar($payload['order_date'] ?? null) ? (string) $payload['order_date'] : ''),
            'annex: '.(is_scalar($payload['annex'] ?? null) ? (string) $payload['annex'] : ''),
        ];

        foreach (MozUkraineClient::LABELS as $field) {
            $values = $this->lines($payload, $field);

            if ($field === 'sites') {
                $values = array_map(static fn (string $line): string => preg_match('/^(\d+\))/u', $line, $match) === 1 ? $match[1] : $line, $values);
            }

            if ($values !== []) {
                $lines[] = $field.":\n".implode("\n", $values);
            }
        }

        return implode("\n", $lines);
    }
}
