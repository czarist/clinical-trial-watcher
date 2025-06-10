<?php

namespace App\Models\ClinicalTrials;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClinicalTrialCondition extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'clinical_trial_id',
        'condition',
    ];

    public function trial(): BelongsTo
    {
        return $this->belongsTo(ClinicalTrial::class, 'clinical_trial_id');
    }
}
