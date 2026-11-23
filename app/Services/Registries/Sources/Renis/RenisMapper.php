<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Renis;

use App\Enums\StudyStatus;
use App\Exceptions\InvalidStudyPayload;
use App\Services\ClinicalTrials\Import\MappedStudy;
use App\Services\ClinicalTrials\Import\RawDocument;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\Registries\Sources\MapsRegistryRecords;

/**
 * Maps one row of the RENIS workbook. Records are thin: a Spanish title, status, phase,
 * sponsors, health category, intervention type, registration date (an Excel serial number)
 * and participant counts; every study takes place in Argentina. REGISTRO_SECUNDARIO often
 * holds the study's NCT number, which links it to its ClinicalTrials.gov record.
 */
final class RenisMapper implements StudyPayloadMapper
{
    use MapsRegistryRecords;

    /** Payload key the client adds with the URL of the workbook the row was read from. */
    public const string FILE_URL_KEY = '_file_url';

    private const array STATUSES = [
        'finalizado' => StudyStatus::Completed,
        'activo incorporando pacientes' => StudyStatus::Recruiting,
        'activo no incorporando' => StudyStatus::ActiveNotRecruiting,
        'activo no incorporando pacientes' => StudyStatus::ActiveNotRecruiting,
        'no iniciado aun' => StudyStatus::NotYetRecruiting,
        'suspendido' => StudyStatus::Suspended,
    ];

    private const array PHASES = [
        'fase 0' => 'EARLY_PHASE1',
        'fase 1' => 'PHASE1',
        'fase 1/2' => 'PHASE1, PHASE2',
        'fase 2' => 'PHASE2',
        'fase 2/3' => 'PHASE2, PHASE3',
        'fase 3' => 'PHASE3',
        'fase 4' => 'PHASE4',
        'no corresponde' => 'NA',
    ];

    private const array STUDY_TYPES = [
        'experimental' => 'INTERVENTIONAL',
        'observacional' => 'OBSERVATIONAL',
    ];

    public function payloadId(array $payload): ?string
    {
        $id = $this->short($payload['ID_RENIS'] ?? null);

        return $id !== null && preg_match('/^\d+(\.0+)?$/', $id) === 1 ? (string) (int) $id : null;
    }

    public function map(array $payload): MappedStudy
    {
        $id = $this->payloadId($payload) ?? throw new InvalidStudyPayload('The RENIS row has no valid ID_RENIS.');

        foreach (RenisClient::PERSONAL_COLUMNS as $column) {
            unset($payload[$column]);
        }

        $fileUrl = is_string($payload[self::FILE_URL_KEY] ?? null) ? $payload[self::FILE_URL_KEY] : null;
        unset($payload[self::FILE_URL_KEY]);

        $status = self::STATUSES[$this->key($payload['ESTADO_RECLUTAMIENTO'] ?? null)] ?? null;
        $results = $this->text($payload['OBSERVACIONES_RESULTADOS'] ?? null);
        $dates = ['start_date' => null, 'primary_completion_date' => null, 'completion_date' => null];

        $attributes = [
            'brief_title' => $this->short($payload['TITULO_COMPLETO_ENSAYO'] ?? null) ?? "RENIS {$id}",
            'official_title' => null,
            'acronym' => null,
            'org_study_id' => null,
            'overall_status' => $status?->value,
            'status_verified_date' => null,
            ...$dates,
            'study_first_post_date' => $this->date($payload['FECHA_INCORPORACION'] ?? null),
            'last_update_post_date' => null,
            'has_expanded_access' => false,
            'brief_summary' => null,
            'detailed_description' => null,
            'study_type' => self::STUDY_TYPES[$this->key($payload['TIPO_ESTUDIO'] ?? null)] ?? null,
            'phase' => self::PHASES[$this->key($payload['FASE_INVESTIGACION_EXP'] ?? null)] ?? null,
            'enrollment_count' => $this->count($payload['PARTICIPANTES_MUNDO'] ?? null) ?? $this->count($payload['PARTICIPANTES_ARGENTINA'] ?? null),
            'enrollment_type' => null,
            'sponsor_name' => $this->short($payload['PATROCINADOR_PRINCIPAL'] ?? null),
            'sponsor_class' => null,
            'responsible_party_type' => null,
            'eligibility_criteria' => null,
            'min_age' => null,
            'max_age' => null,
            'gender' => null,
            'is_fda_regulated_drug' => false,
            'is_fda_regulated_device' => false,
            'ipd_sharing' => null,
            'has_results' => $results !== null && mb_strlen($results) >= 20,
        ];

        $relations = $this->emptyRelations();
        $relations['secondaryIds'] = $this->secondaryIdRows(self::citedIdentifiers($payload['REGISTRO_SECUNDARIO'] ?? null));

        $collaborator = $this->short($payload['PATROCINADOR_SECUNDARIO'] ?? null);
        $relations['collaborators'] = $collaborator !== null && $collaborator !== $attributes['sponsor_name']
            ? [['name' => $collaborator, 'class' => null]]
            : [];

        $conditions = [];

        foreach (['CONDICION_SALUD', 'CATEGORIA'] as $column) {
            $condition = $this->short($payload[$column] ?? null);

            if ($condition !== null) {
                $conditions[$condition] = ['condition' => $condition];
            }
        }

        $relations['conditions'] = array_values($conditions);
        $relations['interventions'] = $this->interventions($payload);
        $relations['locations'] = [[
            'facility' => null, 'city' => null, 'state' => null, 'country' => 'Argentina',
            'status' => null, 'zip' => null, 'latitude' => null, 'longitude' => null,
        ]];

        return new MappedStudy(
            source: RenisSource::KEY,
            sourceId: $id,
            attributes: $attributes,
            relations: $relations,
            facts: $this->facts($status?->value, $relations['locations'], $dates, $attributes['has_results']),
            raw: new RawDocument(
                'json',
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                $fileUrl,
            ),
        );
    }

    /**
     * Registry identifiers cited in REGISTRO_SECUNDARIO, which is free text: an NCT number,
     * an EU CT or EudraCT number, or junk such as "NA" or "xx" that is ignored.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function citedIdentifiers(mixed $value): array
    {
        if (! is_scalar($value)) {
            return [];
        }

        $value = (string) $value;
        $found = [];

        preg_match_all('/NCT\s*-?\s*(\d{8})/i', $value, $nct);

        foreach ($nct[1] as $digits) {
            $found[] = ['NCT'.$digits, 'NCT'];
        }

        preg_match_all('/(?<![\d-])((?:19|20)\d{2}-\d{6}-\d{2})(-\d{2})?(?![\d])/', $value, $eu, PREG_SET_ORDER);

        foreach ($eu as $match) {
            $suffix = $match[2] ?? '';

            // EU CT numbers (YYYY-5NNNNN-NN-NN) cited without their last part are incomplete: skipped.
            if (preg_match('/^\d{4}-5/', $match[1]) === 1) {
                if ($suffix !== '') {
                    $found[] = [$match[1].$suffix, 'EU CT'];
                }

                continue;
            }

            $found[] = [$match[1], 'EudraCT'];
        }

        return $found;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, string|null>>
     */
    private function interventions(array $payload): array
    {
        $type = $this->short($payload['TIPO_INTERVENCION'] ?? null);
        $description = $this->text($payload['TIPO_INTERVENCION_DESCRIPCION'] ?? null);

        if ($type === null && $description === null) {
            return [];
        }

        return [[
            'type' => $type,
            'name' => $this->short($description) ?? (string) $type,
            'description' => $description,
        ]];
    }

    private function count(mixed $value): ?int
    {
        $value = $this->short($value);

        return $value !== null && preg_match('/^\d+(\.0+)?$/', $value) === 1 ? (int) $value : null;
    }

    /** Lower-case value without accents, for matching the registry's vocabulary. */
    private function key(mixed $value): string
    {
        $value = mb_strtolower((string) $this->short($value));

        return strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    }
}
