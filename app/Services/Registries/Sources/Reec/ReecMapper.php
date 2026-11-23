<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Reec;

use App\Enums\IdentifierScheme;
use App\Enums\StudyStatus;
use App\Exceptions\InvalidStudyPayload;
use App\Services\ClinicalTrials\Import\MappedStudy;
use App\Services\ClinicalTrials\Import\RawDocument;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\Registries\Identifiers\IdentifierParser;
use App\Services\Registries\Sources\MapsRegistryRecords;

/**
 * Maps one REec study detail (with its search row under `busqueda`). Texts are bilingual
 * (Spanish, and English in the `_en` fields): English is used when present. The sponsor's
 * contact person, email and phone and the site investigators are never imported and are
 * removed from the kept raw record.
 */
final class ReecMapper implements StudyPayloadMapper
{
    use MapsRegistryRecords;

    /**
     * Search `estado` codes. The service documents the labels (No iniciado, Reclutando, Fin
     * reclutamiento, Finalizado) but not their codes: only "2", seen on a study recruiting in
     * Spain, is mapped (unverified); every other code maps to null. Dated events (premature end,
     * interruption, global end) take precedence.
     */
    private const array STATUS_CODES = [
        '2' => StudyStatus::Recruiting,
    ];

    private const array PHASES = ['faseUno' => 'PHASE1', 'faseDos' => 'PHASE2', 'faseTres' => 'PHASE3', 'faseCuatro' => 'PHASE4'];

    /** Personal fields of the sponsor contact and the sites, dropped from the raw record. */
    private const array PERSONAL_KEYS = ['telefono', 'fax', 'mail', 'personaContacto', 'investigador'];

    /** Whether the value is an EU CT or EudraCT number, the identifiers REec authorises trials under. */
    public static function isTrialNumber(string $value): bool
    {
        return preg_match('/^(?:19|20)\d{2}-\d{6}-\d{2}(?:-\d{2})?$/', $value) === 1;
    }

    public function payloadId(array $payload): ?string
    {
        $id = is_string($payload['identificador'] ?? null) ? trim($payload['identificador']) : '';

        return $id !== '' && mb_strlen($id) <= 100 ? $id : null;
    }

    public function map(array $payload): MappedStudy
    {
        $id = $this->payloadId($payload) ?? throw new InvalidStudyPayload('The REec study has no identificador.');

        $info = $this->array($payload['informacion'] ?? null);
        $calendar = $this->array($payload['calendario'] ?? null);
        $sponsor = $this->array($payload['organismo'] ?? null);
        $search = $this->array($payload['busqueda'] ?? null);
        $status = $this->status($calendar, $search);

        $dates = [
            'start_date' => $this->reecDate($calendar['fechaInicioReal'] ?? null) ?? $this->reecDate($calendar['fechaInicioPrevista'] ?? null),
            'primary_completion_date' => null,
            'completion_date' => $this->reecDate($calendar['fechaFinRealGlobal'] ?? null) ?? $this->reecDate($calendar['fechaFinPrevista'] ?? null),
        ];

        $attributes = [
            'brief_title' => $this->short($this->bilingual($info, 'tituloPublico')) ?? $this->short($this->clean($search['titulo_en'] ?? null)) ?? $id,
            'official_title' => $this->bilingual($info, 'tituloCientifico'),
            'acronym' => $this->short($this->clean($payload['acronimo'] ?? null)),
            'org_study_id' => $this->short($payload['protocolo'] ?? null),
            'overall_status' => $status?->value,
            'status_verified_date' => null,
            ...$dates,
            'study_first_post_date' => $this->reecDate($calendar['fechaRegistro'] ?? null),
            'last_update_post_date' => null,
            'has_expanded_access' => false,
            'brief_summary' => $this->bilingual($info, 'objetivoPrincipal'),
            'detailed_description' => null,
            'study_type' => self::isTrialNumber($id) ? 'INTERVENTIONAL' : null,
            'phase' => $this->phase($this->array($payload['proposito'] ?? null)),
            'enrollment_count' => null,
            'enrollment_type' => null,
            'sponsor_name' => $this->short($this->clean($sponsor['promotor'] ?? null)),
            'sponsor_class' => null,
            'responsible_party_type' => null,
            'eligibility_criteria' => $this->eligibility($info),
            'min_age' => null,
            'max_age' => null,
            'gender' => null,
            'is_fda_regulated_drug' => false,
            'is_fda_regulated_device' => false,
            'ipd_sharing' => null,
            'has_results' => ($search['resultados'] ?? null) === '1',
        ];

        $relations = $this->emptyRelations();
        $relations['secondaryIds'] = $this->secondaryIds($id);
        $funder = $this->short($this->clean($sponsor['financiador'] ?? null));
        $relations['collaborators'] = $funder !== null && $funder !== $attributes['sponsor_name'] ? [['name' => $funder, 'class' => null]] : [];
        $relations['conditions'] = $this->conditions($info, $search);
        $relations['keywords'] = $this->keywords($payload['areasTerapeuticas'] ?? null);
        $relations['interventions'] = $this->interventions($payload['intervenciones'] ?? null);
        $relations['locations'] = $this->locations($payload['centros'] ?? null);
        $relations['outcomes'] = $this->outcomes($info);

        return new MappedStudy(
            source: ReecSource::KEY,
            sourceId: $id,
            attributes: $attributes,
            relations: $relations,
            facts: $this->facts($status?->value, $relations['locations'], $dates, $attributes['has_results']),
            raw: new RawDocument(
                'json',
                json_encode($this->withoutPersonalData($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                'https://reec.aemps.es/reec-services/json/detalle/'.rawurlencode($id),
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $calendar
     * @param  array<string, mixed>  $search
     */
    private function status(array $calendar, array $search): ?StudyStatus
    {
        $interrupted = $this->reecDate($calendar['fechaInterrupcion'] ?? null);
        $restarted = $this->reecDate($calendar['fechaReinicio'] ?? null);

        return match (true) {
            $this->reecDate($calendar['fechaFinPrematuro'] ?? null) !== null => StudyStatus::Terminated,
            $interrupted !== null && ($restarted === null || $restarted < $interrupted) => StudyStatus::Suspended,
            $this->reecDate($calendar['fechaFinRealGlobal'] ?? null) !== null => StudyStatus::Completed,
            default => self::STATUS_CODES[(string) ($search['estado'] ?? '')] ?? null,
        };
    }

    /**
     * @param  array<string, mixed>  $purpose
     */
    private function phase(array $purpose): ?string
    {
        $phases = [];

        foreach (self::PHASES as $key => $phase) {
            if ((string) ($purpose[$key] ?? '') === '1') {
                $phases[] = $phase;
            }
        }

        return $phases === [] ? null : implode(', ', $phases);
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function eligibility(array $info): ?string
    {
        $sections = array_filter([
            'Inclusion criteria' => $this->bilingual($info, 'criteriosInclusion'),
            'Exclusion criteria' => $this->bilingual($info, 'criteriosExclusion'),
        ]);

        return $sections === [] ? null : implode("\n\n", array_map(
            fn (string $heading, string $text): string => "{$heading}:\n{$text}",
            array_keys($sections),
            $sections,
        ));
    }

    /**
     * The trial's own EU CT or EudraCT number, so the record links to CTIS and the EU CTR.
     *
     * @return list<array<string, string|null>>
     */
    private function secondaryIds(string $id): array
    {
        $identifier = (new IdentifierParser)->parse($id);

        return $identifier === null ? [] : $this->secondaryIdRows([[
            $identifier->value,
            $identifier->scheme === IdentifierScheme::EuCt ? 'EU CT number' : 'EudraCT',
        ]]);
    }

    /**
     * @param  array<string, mixed>  $info
     * @param  array<string, mixed>  $search
     * @return list<array<string, string>>
     */
    private function conditions(array $info, array $search): array
    {
        $rows = [];

        foreach ([
            $this->bilingual($info, 'indicacionPublica') ?? $this->clean($search['indicacion_en'] ?? null) ?? $this->clean($search['indicacion_es'] ?? null),
            $this->bilingual($info, 'indicacionCientifica'),
        ] as $value) {
            $condition = $this->short($value);

            if ($condition !== null) {
                $rows[$condition] = ['condition' => $condition];
            }
        }

        return array_values($rows);
    }

    /**
     * Therapeutic areas, given as objects or, in some records, as the text of a list.
     *
     * @return list<array<string, string>>
     */
    private function keywords(mixed $areas): array
    {
        $names = [];

        if (is_array($areas) && array_key_exists('area', $areas)) {
            $areas = $areas['area'];
        }

        if (is_string($areas)) {
            preg_match_all("/'nombre_en':\s*'([^']+)'/", $areas, $matches);
            $names = $matches[1];
        } else {
            foreach (ReecClient::items($areas) as $area) {
                $names[] = $area['nombre_en'] ?? $area['nombre_es'] ?? null;
            }
        }

        $rows = [];

        foreach ($names as $name) {
            $keyword = $this->short($this->clean($name));

            if ($keyword !== null) {
                $rows[$keyword] = ['keyword' => $keyword];
            }
        }

        return array_values($rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function interventions(mixed $interventions): array
    {
        $rows = [];

        foreach (ReecClient::items(is_array($interventions) ? ($interventions['intervencion'] ?? null) : null) as $item) {
            $scientific = $this->short($this->clean($item['nombreCientifico'] ?? null));
            $name = $this->short($this->clean($item['nombreComercial'] ?? null)) ?? $scientific;

            if ($name === null) {
                continue;
            }

            $details = array_filter([
                $scientific !== null && $scientific !== $name ? "Scientific name: {$scientific}" : null,
                ($atc = $this->short($item['atcs'] ?? null)) !== null ? "ATC: {$atc}" : null,
                ($route = $this->short($item['viasAdministracion'] ?? null)) !== null ? "Route: {$route}" : null,
            ]);

            $rows[$name] = ['type' => 'DRUG', 'name' => $name, 'description' => $details === [] ? null : implode('; ', $details)];
        }

        return array_values($rows);
    }

    /**
     * Spanish sites (organisation and address only; the investigator is dropped).
     *
     * @return list<array<string, mixed>>
     */
    private function locations(mixed $sites): array
    {
        $rows = [];

        foreach (ReecClient::items(is_array($sites) ? ($sites['centro'] ?? null) : null) as $site) {
            $facility = $this->text($this->clean($site['nombre'] ?? null));
            $city = $this->short($this->clean($site['localidad'] ?? null));

            if ($facility === null && $city === null) {
                continue;
            }

            $rows[] = [
                'facility' => $facility,
                'city' => $city,
                'state' => $this->short($this->clean($site['provincia'] ?? null)),
                'country' => 'Spain',
                'status' => null,
                'zip' => $this->short($site['codPostal'] ?? null),
                'latitude' => null,
                'longitude' => null,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $info
     * @return list<array<string, mixed>>
     */
    private function outcomes(array $info): array
    {
        $rows = [];

        foreach (['PRIMARY' => ['variablesPrincipales', 'momentosPrincipales'], 'SECONDARY' => ['variablesSecundarias', 'momentosSecundarios']] as $type => [$measure, $timeFrame]) {
            $text = $this->bilingual($info, $measure);

            if ($text !== null) {
                $rows[] = ['type' => $type, 'measure' => $text, 'description' => null, 'time_frame' => $this->bilingual($info, $timeFrame)];
            }
        }

        return $rows;
    }

    /**
     * The English text of a field ("{$key}_en"), else the Spanish one.
     *
     * @param  array<string, mixed>  $info
     */
    private function bilingual(array $info, string $key): ?string
    {
        return $this->clean($info[$key.'_en'] ?? null) ?? $this->clean($info[$key] ?? null);
    }

    /** Text without the HTML entities and line-break tags REec embeds; placeholders become null. */
    private function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = preg_replace('/<br\s*\/?>/i', "\n", $value) ?? $value;
        $value = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $value === '' || in_array(strtolower($value), ['no aportado', 'not provided'], true) ? null : $value;
    }

    /** A dd-MM-yyyy date of the service. */
    private function reecDate(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $this->date(trim($value), 'd-m-Y', 'd/m/Y') : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
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
