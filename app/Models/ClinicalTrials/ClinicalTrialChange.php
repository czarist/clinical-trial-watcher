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
 *
 * details holds the structured before-and-after values (see ChangeDetector); it is null for
 * a new study and for changes recorded before structured tracking existed. fingerprint is the
 * change's stable identity (its "version" for shared AI summaries).
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
        'details',
        'fingerprint',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'type' => TrialChangeType::class,
            'details' => 'array',
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
