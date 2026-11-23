<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImportRunStatus;
use App\Models\ClinicalTrials\ClinicalTrialChange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One execution of the ClinicalTrials.gov import, with its counters.
 */
class ImportRun extends Model
{
    use HasFactory;

    /** Same defaults as the table, so counters are 0 before the first save. */
    protected $attributes = [
        'pages' => 0,
        'studies_created' => 0,
        'studies_updated' => 0,
        'studies_unchanged' => 0,
        'studies_failed' => 0,
    ];

    protected $fillable = [
        'status',
        'updated_since',
        'pages',
        'studies_created',
        'studies_updated',
        'studies_unchanged',
        'studies_failed',
        'error',
        'started_at',
        'finished_at',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'status' => ImportRunStatus::class,
            'updated_since' => 'date:Y-m-d',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'pages' => 'integer',
            'studies_created' => 'integer',
            'studies_updated' => 'integer',
            'studies_unchanged' => 'integer',
            'studies_failed' => 'integer',
        ];
    }

    public function trialChanges(): HasMany
    {
        return $this->hasMany(ClinicalTrialChange::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeSucceeded(Builder $query): void
    {
        $query->where('status', ImportRunStatus::Succeeded);
    }

    /**
     * @return array<string, int|string|null>
     */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'updated_since' => $this->updated_since?->toDateString(),
            'pages' => $this->pages,
            'created' => $this->studies_created,
            'updated' => $this->studies_updated,
            'unchanged' => $this->studies_unchanged,
            'failed' => $this->studies_failed,
        ];
    }
}
