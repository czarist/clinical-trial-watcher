<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\EdaEgypt;

use App\Enums\StudyStatus;
use App\Exceptions\InvalidStudyPayload;
use App\Services\ClinicalTrials\Import\MappedStudy;
use App\Services\ClinicalTrials\Import\RawDocument;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\Registries\Sources\MapsRegistryRecords;

/**
 * Maps one row of the EDA registry table to the common study model.
 *
 * The record id is the study code (the protocol number given at submission) with the spaces
 * left by wrapped lines removed: the registry has no identifier of its own. It is the record's
 * key within this registry only; it is never used to link registrations of other registries,
 * and the table cites no registry identifier (NCT, PACTR...), so no secondary identifiers.
 *
 * The status cell lists events ("Approved 26/03/2019 Completed 03/11/2021"); the last one
 * gives the overall status. Approval, refusal and cancellation by EDA have no recruitment
 * equivalent, so they map to null.
 */
final class EdaEgyptMapper implements StudyPayloadMapper
{
    use MapsRegistryRecords;

    /** Status events, matched case-insensitively; the first matching pattern at a position wins. */
    private const array STATUS_EVENTS = [
        '/recruitment\s+(?:completion|completed)/i' => StudyStatus::ActiveNotRecruiting,
        '/(?:early\s+)?terminat(?:ed|ion)/i' => StudyStatus::Terminated,
        '/\brecruiting\b/i' => StudyStatus::Recruiting,
        '/\bcompleted?\b/i' => StudyStatus::Completed,
        '/\bwithdrawn\b/i' => StudyStatus::Withdrawn,
        '/\bsuspen(?:ded|sion)\b|\bdosing\s+pause\b/i' => StudyStatus::Suspended,
        '/\b(?:conditional\s+|final\s+)?approv(?:ed|al)\b|\brefus(?:ed|al)\b|\bcancell?ation\b|\bresumed\b/i' => null,
    ];

    private const array INTERVENTION_TYPES = [
        'biological' => 'BIOLOGICAL',
        'pharmaceutical' => 'DRUG',
        'innovative' => 'DRUG',
        'medical device' => 'DEVICE',
        'herbal' => 'OTHER',
    ];

    private const array PHASES = ['I' => 'PHASE1', 'II' => 'PHASE2', 'III' => 'PHASE3', 'IV' => 'PHASE4'];

    #[\Override]
    public function payloadId(array $payload): ?string
    {
        $code = $this->short($payload['study_code'] ?? null);

        return $code === null ? null : (string) preg_replace('/\s+/u', '', $code);
    }

    #[\Override]
    public function map(array $payload): MappedStudy
    {
        $id = $this->payloadId($payload);

        if ($id === null) {
            throw new InvalidStudyPayload('The EDA registry row has no study code.');
        }

        $status = $this->text($payload['status'] ?? null);
        $overallStatus = $this->overallStatus($status);
        $dates = [
            'start_date' => null,
            'primary_completion_date' => null,
            'completion_date' => $this->eventDate($status, '/\bcompleted?\b\s*:?\s*/i'),
        ];

        [$sponsor, $cro] = $this->sponsor($this->short($payload['sponsor'] ?? null, 1000));
        $relations = $this->emptyRelations();
        $relations['locations'] = $this->sites($this->text($payload['sites'] ?? null));
        $relations['collaborators'] = $cro === null ? [] : [['name' => $cro, 'class' => null]];
        $condition = $this->short($payload['conditions'] ?? null);
        $relations['conditions'] = $condition === null ? [] : [['condition' => $condition]];
        $relations['interventions'] = $this->interventions($this->short($payload['interventions'] ?? null, 2000));
        $title = $this->short($payload['title'] ?? null, 2000);

        return new MappedStudy(
            source: EdaEgyptSource::KEY,
            sourceId: $id,
            attributes: [
                'brief_title' => $title,
                'official_title' => $title,
                'org_study_id' => $this->short($payload['study_code'] ?? null),
                'overall_status' => $overallStatus?->value,
                'status_verified_date' => $this->dmy($payload['edition'] ?? null),
                'completion_date' => $dates['completion_date'],
                'study_type' => 'INTERVENTIONAL',
                'phase' => $this->phase($this->short($payload['phase'] ?? null)),
                'sponsor_name' => $sponsor,
            ],
            relations: $relations,
            facts: $this->facts($overallStatus?->value, $relations['locations'], $dates),
            raw: new RawDocument('pdf', $this->rawText($payload), is_string($payload['url'] ?? null) ? $payload['url'] : null),
        );
    }

    /** The status of the last event in the cell, or null when it has no recruitment equivalent. */
    private function overallStatus(?string $status): ?StudyStatus
    {
        if ($status === null) {
            return null;
        }

        $last = null;

        foreach (self::STATUS_EVENTS as $pattern => $value) {
            if (preg_match_all($pattern, $status, $matches, PREG_OFFSET_CAPTURE) > 0) {
                $position = (int) end($matches[0])[1];

                if ($last === null || $position > $last[0]) {
                    $last = [$position, $value];
                }
            }
        }

        return $last[1] ?? null;
    }

    private function eventDate(?string $status, string $event): ?string
    {
        if ($status === null || preg_match_all(substr($event, 0, -2).'(\d{1,2}[\/\\\\]\d{1,2}[\/\\\\]\d{4})/i', $status, $matches) === 0) {
            return null;
        }

        return $this->dmy(end($matches[1]));
    }

    private function dmy(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('#^(\d{1,2})[/\\\\](\d{1,2})[/\\\\](\d{4})$#', trim($value), $match) !== 1) {
            return null;
        }

        return checkdate((int) $match[2], (int) $match[1], (int) $match[3])
            ? sprintf('%04d-%02d-%02d', $match[3], $match[2], $match[1])
            : null;
    }

    /** Phases such as "III", "II/III", "IIb", "Ib/II" or "III (pivotal)". */
    private function phase(?string $value): ?string
    {
        if ($value === null || preg_match_all('/(?<![A-Za-z])(IV|III|II|I)[ab]?(?![A-Za-z])/', $value, $matches) === 0) {
            return null;
        }

        $phases = array_values(array_unique(array_map(static fn (string $numeral): string => self::PHASES[$numeral], $matches[1])));
        sort($phases);

        return implode(', ', $phases);
    }

    /**
     * @return array{0: string|null, 1: string|null} sponsor and contract research organisation
     */
    private function sponsor(?string $value): array
    {
        if ($value === null) {
            return [null, null];
        }

        $parts = preg_split('/\bCRO\s*:/i', $value, 2) ?: [$value];
        $sponsor = $this->short(preg_replace('/^\s*Sponsor\s*:/i', '', $parts[0]));

        return [$sponsor, $this->short($parts[1] ?? null)];
    }

    /**
     * Numbered sites ("1- CRC, Faculty of Medicine, Alexandria University/NA"), with the site's
     * activation date or "NA" removed.
     *
     * @return list<array<string, mixed>>
     */
    private function sites(?string $value): array
    {
        if ($value === null) {
            return [];
        }

        $sites = [];

        foreach (preg_split('/(?:^|\s)\d{1,2}\s*-\s*(?=\D)/u', $value) ?: [] as $site) {
            $site = preg_replace('#\s*(?:/\s*NA|\((?:\d{1,2}/)?\d{1,2}/\d{4,5}\)|\(NA\))\s*$#i', '', $site);
            $facility = $this->short($site);

            if ($facility !== null) {
                $sites[mb_strtolower($facility)] = [
                    'facility' => $facility,
                    'city' => null,
                    'state' => null,
                    'country' => 'Egypt',
                    'status' => null,
                    'zip' => null,
                    'latitude' => null,
                    'longitude' => null,
                ];
            }
        }

        return array_values($sites);
    }

    /**
     * The product type ("(Biological)", "Pharmaceutical") and the products named with it.
     *
     * @return list<array<string, string|null>>
     */
    private function interventions(?string $value): array
    {
        if ($value === null) {
            return [];
        }

        $type = null;

        foreach (self::INTERVENTION_TYPES as $label => $mapped) {
            $pattern = '/\(?\b'.str_replace(' ', '\s+', $label).'\b\)?/i';

            if (preg_match($pattern, $value) === 1) {
                $type = $mapped;
                $value = (string) preg_replace($pattern, '', $value, 1);

                break;
            }
        }

        $name = $this->short(trim($value, " \t\n\r\0\x0B()"));

        return $name === null ? [] : [['type' => $type, 'name' => $name, 'description' => null]];
    }

    /**
     * The row's cells as printed in the PDF, one "label: value" line each.
     *
     * @param  array<string, mixed>  $payload
     */
    private function rawText(array $payload): string
    {
        $lines = [];

        foreach (EdaEgyptClient::FIELDS as $field) {
            $lines[] = $field.': '.(is_scalar($payload[$field] ?? null) ? (string) $payload[$field] : '');
        }

        $lines[] = 'edition: '.(is_scalar($payload['edition'] ?? null) ? (string) $payload['edition'] : '');

        return implode("\n", $lines);
    }
}
