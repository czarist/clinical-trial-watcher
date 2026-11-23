<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\ClinicalTrials\ClinicalTrialChange;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A change described in a digest, with its rank and the summary used (AI or factual).
 */
class DigestItem extends Model
{
    protected $fillable = [
        'digest_id',
        'clinical_trial_change_id',
        'change_summary_id',
        'rank',
        'score',
        'summary_source',
    ];

    public function digest(): BelongsTo
    {
        return $this->belongsTo(Digest::class);
    }

    public function change(): BelongsTo
    {
        return $this->belongsTo(ClinicalTrialChange::class, 'clinical_trial_change_id');
    }

    public function summary(): BelongsTo
    {
        return $this->belongsTo(ChangeSummary::class, 'change_summary_id');
    }
}
