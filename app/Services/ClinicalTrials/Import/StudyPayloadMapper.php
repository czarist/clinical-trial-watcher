<?php

declare(strict_types=1);

namespace App\Services\ClinicalTrials\Import;

use App\Exceptions\InvalidStudyPayload;

/**
 * Translates one registry's study payload to the common study model.
 */
interface StudyPayloadMapper
{
    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidStudyPayload when the payload has no identifier in its registry
     */
    public function map(array $payload): MappedStudy;

    /**
     * The payload's identifier in its registry, for logging a payload that fails to import.
     *
     * @param  array<string, mixed>  $payload
     */
    public function payloadId(array $payload): ?string;
}
