<?php
namespace App\Models\ClinicalTrials;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClinicalTrialSecondaryId extends Model
{
    use HasFactory;

    public $timestamps = true;

    protected $fillable = [
        'clinical_trial_id',
        'secondary_id',
        'type',
        'domain',
        'link',
    ];

    public function trial(): BelongsTo
    {
        return $this->belongsTo(ClinicalTrial::class, 'clinical_trial_id');
    }
}
