<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Exceptions\InvalidStudyPayload;
use App\Models\ClinicalTrials\ClinicalTrial;
use App\Services\ClinicalTrials\Import\MappedStudy;
use App\Services\ClinicalTrials\Import\StudyPage;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\RegistrySource;
use Carbon\CarbonInterface;

/**
 * A second registry for tests, identified by ISRCTN-style numbers. Its payloads are small
 * arrays set in $payloads: ['id', 'title', 'status', 'cites' => [identifiers], 'updated'].
 */
final class FakeRegistrySource implements RegistrySource, StudyPayloadMapper, StudyRegistryClient
{
    public const string KEY = 'test_registry';

    /** @var list<array<string, mixed>> */
    public static array $payloads = [];

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'Test Registry';
    }

    public function client(): StudyRegistryClient
    {
        return $this;
    }

    public function mapper(): StudyPayloadMapper
    {
        return $this;
    }

    public function recordUrl(string $sourceId): ?string
    {
        return preg_match('/^ISRCTN\d{8}$/', $sourceId) === 1 ? 'https://registry.test/'.$sourceId : null;
    }

    public function fetchPage(int $pageSize, ?string $pageToken = null, ?CarbonInterface $updatedSince = null): StudyPage
    {
        return new StudyPage(self::$payloads, null);
    }

    public function payloadId(array $payload): ?string
    {
        return $payload['id'] ?? null;
    }

    public function map(array $payload): MappedStudy
    {
        if (! is_string($payload['id'] ?? null)) {
            throw InvalidStudyPayload::missingNctId();
        }

        $relations = array_fill_keys(ClinicalTrial::SYNCED_RELATIONS, []);
        $relations['secondaryIds'] = array_map(fn (string $id): array => ['secondary_id' => $id, 'type' => null, 'domain' => null, 'link' => null], $payload['cites'] ?? []);

        return new MappedStudy(
            source: self::KEY,
            sourceId: $payload['id'],
            attributes: [
                'brief_title' => $payload['title'] ?? 'Untitled study',
                'overall_status' => $payload['status'] ?? null,
                'last_update_post_date' => $payload['updated'] ?? '2026-11-20',
            ],
            relations: $relations,
        );
    }
}
