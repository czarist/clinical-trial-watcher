<?php

namespace App\Models\ClinicalTrials;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClinicalTrialCollaborator extends Model
{
    use HasFactory;

    public $timestamps = true;

    protected $fillable = [
        'clinical_trial_id',
        'name',
        'class',
        'role', // pode ser útil para diferenciar tipos de colaboração
    ];

    public function trial(): BelongsTo
    {
        return $this->belongsTo(ClinicalTrial::class, 'clinical_trial_id');
    }
}
