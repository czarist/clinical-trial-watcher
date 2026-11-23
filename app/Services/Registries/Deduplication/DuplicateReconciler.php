<?php

declare(strict_types=1);

namespace App\Services\Registries\Deduplication;

use App\Models\ClinicalTrials\ClinicalTrial;
use App\Models\ClinicalTrials\ClinicalTrialSecondaryId;
use App\Services\ClinicalTrials\Import\MappedStudy;
use App\Services\Registries\Identifiers\IdentifierParser;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

/**
 * Rebuilds the identifiers, links and groups of stored records without fetching them again:
 * for records imported before deduplication existed, or after the identifier rules change.
 * Running it again changes nothing; reviewed links keep their decision.
 */
final readonly class DuplicateReconciler
{
    public function __construct(
        private ConnectionInterface $db,
        private DuplicateLinker $linker,
        private IdentifierParser $parser = new IdentifierParser,
    ) {}

    /**
     * @param  (callable(int): void)|null  $progress  called with the number of records done after each chunk
     * @return array{records: int, grouped: int, conflicts: int}
     */
    public function reconcileAll(?string $source = null, ?callable $progress = null): array
    {
        $done = 0;

        ClinicalTrial::query()
            ->when($source !== null, fn ($query) => $query->where('source', $source))
            ->select(['id', 'source', 'source_id', 'org_study_id'])
            ->with('secondaryIds:id,clinical_trial_id,secondary_id,type')
            ->chunkById(200, function (Collection $trials) use (&$done, $progress): void {
                foreach ($trials as $trial) {
                    $this->db->transaction(fn () => $this->linker->index($trial, $this->parser->fromStudy($this->asMapped($trial))), 3);
                }

                $done += $trials->count();

                if ($progress !== null) {
                    $progress($done);
                }
            });

        return [
            'records' => $done,
            'grouped' => ClinicalTrial::query()->whereNotNull('canonical_trial_id')->count(),
            'conflicts' => $this->db->table('clinical_trial_links')->where('status', 'conflict')->count(),
        ];
    }

    private function asMapped(ClinicalTrial $trial): MappedStudy
    {
        return new MappedStudy(
            source: $trial->source,
            sourceId: $trial->source_id,
            attributes: ['org_study_id' => $trial->org_study_id],
            relations: ['secondaryIds' => $trial->secondaryIds
                ->map(fn (ClinicalTrialSecondaryId $row): array => ['secondary_id' => $row->secondary_id, 'type' => $row->type])
                ->all()],
        );
    }
}
