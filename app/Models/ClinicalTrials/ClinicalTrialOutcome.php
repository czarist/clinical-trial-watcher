<?php
namespace App\Models\ClinicalTrials;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function trial(): BelongsTo
    {
        return $this->belongsTo(ClinicalTrial::class, 'clinical_trial_id');
    }
}
