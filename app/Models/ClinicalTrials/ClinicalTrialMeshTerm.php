<?php

namespace App\Models\clinicalTrials;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClinicalTrialMeshTerm extends Model
{
    use HasFactory;

    public $timestamps = true;

    protected $fillable = [
        'clinical_trial_id',
        'mesh_id',
        'term',
        'is_primary',
    ];

    public function trial(): BelongsTo
    {
        return $this->belongsTo(ClinicalTrial::class, 'clinical_trial_id');
    }
}
