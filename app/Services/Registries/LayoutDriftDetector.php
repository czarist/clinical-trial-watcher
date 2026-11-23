<?php

declare(strict_types=1);

namespace App\Services\Registries;

use App\Models\ImportRun;

/**
 * Notices when a registry read by download or web page probably changed its layout: a full
 * run that parses far fewer records than the last successful full run, or any run whose
 * share of filled fields drops sharply. The run is then marked partial instead of trusted;
 * imports never delete studies, so nothing is removed.
 */
final readonly class LayoutDriftDetector
{
    public function __construct(
        private int $minPreviousRecords,
        private float $recordRatio,
        private float $fillRatioDrop,
    ) {}

    /** A description of the drift, or null when the run looks like the previous one. */
    public function check(RegistrySource $source, ImportRun $run): ?string
    {
        if (! $source->access()->method->detectsLayoutDrift()) {
            return null;
        }

        $isFull = $run->updated_since === null;
        $previous = ImportRun::query()
            ->where('source', $run->source)
            ->succeeded()
            ->whereKeyNot($run->getKey())
            ->when($isFull, fn ($query) => $query->whereNull('updated_since'))
            ->latest('started_at')
            ->latest('id')
            ->first();

        if ($previous === null) {
            return null;
        }

        if ($isFull && $previous->recordsSeen() >= $this->minPreviousRecords
            && $run->recordsSeen() < $previous->recordsSeen() * $this->recordRatio) {
            return sprintf(
                'Possible layout change: %d records parsed, against %d in run #%d. Check the parser before trusting this run.',
                $run->recordsSeen(),
                $previous->recordsSeen(),
                $previous->id,
            );
        }

        if ($run->field_fill_ratio !== null && $previous->field_fill_ratio !== null
            && (float) $run->field_fill_ratio < (float) $previous->field_fill_ratio - $this->fillRatioDrop) {
            return sprintf(
                'Possible layout change: %d%% of mapped fields filled, against %d%% in run #%d. Check the parser before trusting this run.',
                (int) round((float) $run->field_fill_ratio * 100),
                (int) round((float) $previous->field_fill_ratio * 100),
                $previous->id,
            );
        }

        return null;
    }
}
