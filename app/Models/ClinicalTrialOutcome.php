<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClinicalTrialOutcome extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'clinical_trial_id',
        'type',
        'measure',
        'description',
        'time_frame',
    ];

    public function trial()
    {
        return $this->belongsTo(ClinicalTrial::class, 'clinical_trial_id');
    }
}
