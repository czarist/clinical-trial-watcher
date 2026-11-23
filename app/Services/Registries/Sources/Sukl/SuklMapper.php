<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Sukl;

use App\Exceptions\InvalidStudyPayload;
use App\Services\ClinicalTrials\Import\MappedStudy;
use App\Services\ClinicalTrials\Import\RawDocument;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\Registries\Sources\MapsRegistryRecords;

/**
 * Maps one SÚKL trial detail (`detail/{EudraCT}/jazyk/en`). The detail has no recruitment
 * status, phase, enrollment or last-update date: those stay null. Czech sites are imported
 * from their address only; the investigator named in `pracoviste[].nazev` is never imported
 * and is removed from the kept raw record.
 */
final class SuklMapper implements StudyPayloadMapper
{
    use MapsRegistryRecords;

    public function payloadId(array $payload): ?string
    {
        $id = is_string($payload['eudract'] ?? null) ? trim($payload['eudract']) : '';

        return preg_match(SuklSource::ID_PATTERN, $id) === 1 ? $id : null;
    }

    public function map(array $payload): MappedStudy
    {
        $id = $this->payloadId($payload) ?? throw new InvalidStudyPayload('The SÚKL trial has no valid EudraCT number.');

        $dates = [
            'start_date' => $this->suklDate($payload['datumZahajeni'] ?? null),
            'primary_completion_date' => null,
            'completion_date' => $this->suklDate($payload['datumUkonceni'] ?? null),
        ];

        $population = $this->population($payload['pozadavkyNaPopulaci'] ?? null);

        $attributes = [
            'brief_title' => $this->short($payload['nazev'] ?? null) ?? $id,
            // The database has one title; it is repeated in full only when brief_title cuts it.
            'official_title' => mb_strlen((string) $this->text($payload['nazev'] ?? null)) > 255 ? $this->text($payload['nazev']) : null,
            'acronym' => null,
            'org_study_id' => $this->short($payload['protokol'] ?? null),
            // The detail publishes no status; an end date in Czechia says nothing of the trial elsewhere.
            'overall_status' => null,
            'status_verified_date' => null,
            ...$dates,
            'study_first_post_date' => null,
            'last_update_post_date' => null,
            'has_expanded_access' => false,
            'brief_summary' => null,
            'detailed_description' => $this->description($payload),
            // Every trial in the database is a medicinal product trial authorised by SÚKL.
            'study_type' => 'INTERVENTIONAL',
            'phase' => null,
            'enrollment_count' => null,
            'enrollment_type' => null,
            'sponsor_name' => $this->short($payload['zadavatel'] ?? null),
            'sponsor_class' => null,
            'responsible_party_type' => null,
            'eligibility_criteria' => $population === [] ? null : 'Population: '.implode(', ', $population),
            'min_age' => null,
            'max_age' => null,
            'gender' => $this->gender($population),
            'is_fda_regulated_drug' => false,
            'is_fda_regulated_device' => false,
            'ipd_sharing' => null,
            'has_results' => false,
        ];

        $relations = $this->emptyRelations();
        // The record's own EudraCT number links it to the EU CTR and CTIS records of the trial.
        $relations['secondaryIds'] = $this->secondaryIdRows([[$id, 'EudraCT']]);
        $relations['conditions'] = $this->rows('condition', [$payload['diagnoza'] ?? null]);
        $relations['keywords'] = $this->rows('keyword', [$payload['indikacniSkupina'] ?? null]);
        $relations['locations'] = $this->locations($payload['pracoviste'] ?? null);

        return new MappedStudy(
            source: SuklSource::KEY,
            sourceId: $id,
            attributes: $attributes,
            relations: $relations,
            facts: $this->facts(null, $relations['locations'], $dates),
            raw: new RawDocument(
                'json',
                json_encode($this->withoutPersonalData($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                SuklClient::BASE_URL.'/detail/'.$id.'/jazyk/en',
            ),
        );
    }

    /**
     * Czech site addresses, written "institution[, department], street, postcode town". The
     * street is dropped; the postcode and town come from the last part.
     *
     * @return list<array<string, mixed>>
     */
    public function locations(mixed $sites): array
    {
        $rows = [];

        foreach (is_array($sites) ? $sites : [] as $site) {
            $address = is_array($site) ? $this->short($site['adresa'] ?? null, 1000) : null;

            if ($address === null) {
                continue;
            }

            $parts = array_values(array_filter(array_map('trim', explode(',', $address)), fn (string $part): bool => $part !== ''));
            $zip = null;
            $city = null;

            if (count($parts) >= 2 && preg_match('/^(\d{3}\s?\d{2})\s+(.+)$/u', (string) end($parts), $match) === 1) {
                [$zip, $city] = [$match[1], $match[2]];
                array_pop($parts);
                // The part before the postcode is the street, when more than the institution remains.
                if (count($parts) >= 2) {
                    array_pop($parts);
                }
            }

            $facility = $this->text(implode(', ', $parts));
            $key = $facility.'|'.$city;

            $rows[$key] = [
                'facility' => $facility,
                'city' => $this->short($city),
                'state' => null,
                'country' => 'Czechia',
                'status' => null,
                'zip' => $this->short($zip),
                'latitude' => null,
                'longitude' => null,
            ];
        }

        return array_values($rows);
    }

    /**
     * @return list<string>
     */
    private function population(mixed $values): array
    {
        $population = [];

        foreach (is_array($values) ? $values : [] as $value) {
            $item = $this->short($value);

            if ($item !== null) {
                $population[$item] = $item;
            }
        }

        return array_values($population);
    }

    /**
     * @param  list<string>  $population
     */
    private function gender(array $population): ?string
    {
        return match ([in_array('Female', $population, true), in_array('Male', $population, true)]) {
            [true, true] => 'ALL',
            [true, false] => 'FEMALE',
            [false, true] => 'MALE',
            default => null,
        };
    }

    /**
     * The approval dates and SÚKL's note, which have no column of their own.
     *
     * @param  array<string, mixed>  $payload
     */
    private function description(array $payload): ?string
    {
        $lines = array_filter([
            ($year = $this->short($payload['rokPredlozeniZadosti'] ?? null)) !== null ? "Application submitted: {$year}" : null,
            ($sukl = $this->suklDate($payload['datumSchvaleniSUKL'] ?? null)) !== null ? "Approved by SÚKL: {$sukl}" : null,
            ($ethics = $this->suklDate($payload['datumSchvaleniMEK'] ?? null)) !== null ? "Approved by the multicentre ethics committee: {$ethics}" : null,
            ($note = $this->text($payload['poznamka'] ?? null)) !== null ? "Note: {$note}" : null,
        ]);

        return $lines === [] ? null : implode("\n", $lines);
    }

    /**
     * @param  list<mixed>  $values
     * @return list<array<string, string>>
     */
    private function rows(string $column, array $values): array
    {
        $rows = [];

        foreach ($values as $value) {
            $text = $this->short($value);

            if ($text !== null) {
                $rows[$text] = [$column => $text];
            }
        }

        return array_values($rows);
    }

    /** A d.m.yyyy date of the database. */
    private function suklDate(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $this->date(trim($value), 'j.n.Y', 'd.m.Y') : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withoutPersonalData(array $payload): array
    {
        if (is_array($payload['pracoviste'] ?? null)) {
            $payload['pracoviste'] = array_map(
                static fn (mixed $site): mixed => is_array($site) ? array_diff_key($site, ['nazev' => true]) : $site,
                $payload['pracoviste'],
            );
        }

        return $payload;
    }
}
