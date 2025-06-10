<?php

namespace App\Models\ClinicalTrials;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClinicalTrialReference extends Model
{
    use HasFactory;

    public $timestamps = true;

    protected $fillable = [
        'clinical_trial_id',
        'pmid',
        'type',
        'citation',
        'url',
    ];

    public function trial(): BelongsTo
    {
        return $this->belongsTo(ClinicalTrial::class, 'clinical_trial_id');
    }
}
