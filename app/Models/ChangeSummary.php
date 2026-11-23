<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\ClinicalTrials\ClinicalTrialChange;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An AI-written factual summary of one change version in one language, shared by every
 * digest that describes that change. Contains only public registry facts.
 */
class ChangeSummary extends Model
{
    protected $fillable = [
        'cache_key',
        'clinical_trial_change_id',
        'language',
        'prompt_version',
        'model',
        'summary',
        'input_tokens',
        'output_tokens',
    ];

    public function change(): BelongsTo
    {
        return $this->belongsTo(ClinicalTrialChange::class, 'clinical_trial_change_id');
    }
}
