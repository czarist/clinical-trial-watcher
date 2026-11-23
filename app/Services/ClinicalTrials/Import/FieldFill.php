<?php

declare(strict_types=1);

namespace App\Services\ClinicalTrials\Import;

/**
 * Average share of filled study columns over the records mapped in a run. A sharp drop
 * from one run to the next suggests the registry changed its layout.
 */
final class FieldFill
{
    private float $sum = 0.0;

    private int $count = 0;

    public function add(MappedStudy $study): void
    {
        $attributes = $study->attributes;
        unset($attributes['raw_json']);

        if ($attributes === []) {
            return;
        }

        $filled = count(array_filter($attributes, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []));
        $this->sum += $filled / count($attributes);
        $this->count++;
    }

    public function ratio(): ?float
    {
        return $this->count === 0 ? null : round($this->sum / $this->count, 4);
    }
}
