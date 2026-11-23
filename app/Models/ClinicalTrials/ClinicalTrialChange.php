<?php

declare(strict_types=1);

namespace App\Models\ClinicalTrials;

use App\Enums\TrialChangeType;
use App\Models\ImportRun;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A change to a study detected by an import: creation, update or overall status change.
 */
class ClinicalTrialChange extends Model
{
    use HasFactory;

    protected $fillable = [
        'clinical_trial_id',
        'import_run_id',
        'type',
        'previous_status',
        'new_status',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'type' => TrialChangeType::class,
        ];
    }

    public function trial(): BelongsTo
    {
        return $this->belongsTo(ClinicalTrial::class, 'clinical_trial_id');
    }

    public function importRun(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class);
    }
}
