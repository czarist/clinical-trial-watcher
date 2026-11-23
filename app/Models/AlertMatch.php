<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AlertMatchReason;
use App\Models\ClinicalTrials\ClinicalTrial;
use App\Models\ClinicalTrials\ClinicalTrialChange;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertMatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'alert_id',
        'clinical_trial_id',
        'clinical_trial_change_id',
        'reason',
        'notified_at',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'reason' => AlertMatchReason::class,
            'notified_at' => 'datetime',
        ];
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    public function trial(): BelongsTo
    {
        return $this->belongsTo(ClinicalTrial::class, 'clinical_trial_id');
    }

    public function change(): BelongsTo
    {
        return $this->belongsTo(ClinicalTrialChange::class, 'clinical_trial_change_id');
    }
}
