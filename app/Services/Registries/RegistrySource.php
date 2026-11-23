<?php

declare(strict_types=1);

namespace App\Services\Registries;

use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;

/**
 * A clinical trial registry the application imports from. Each registry provides a client
 * that reads pages of study payloads and a mapper that turns one payload into the common
 * study model; the importer, change tracking, deduplication, alerts and digests are shared.
 */
interface RegistrySource
{
    /** Stable key stored in `clinical_trials.source` and `import_runs.source`, e.g. "clinicaltrials_gov". */
    public function key(): string;

    /** Name shown to people, e.g. "ClinicalTrials.gov". */
    public function name(): string;

    public function client(): StudyRegistryClient;

    public function mapper(): StudyPayloadMapper;

    /**
     * Public page of a record on the registry, built only from a valid identifier of that
     * registry, or null when the identifier is not valid.
     */
    public function recordUrl(string $sourceId): ?string;
}
