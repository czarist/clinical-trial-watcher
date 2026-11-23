<?php

declare(strict_types=1);

namespace App\Models\ClinicalTrials;

use App\Enums\IdentifierScheme;
use App\Enums\TrialLinkStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Two study records that share a registry identifier, stored once with the lower id first.
 */
class ClinicalTrialLink extends Model
{
    protected $fillable = ['clinical_trial_id', 'linked_trial_id', 'scheme', 'value', 'status', 'reviewed_by', 'reviewed_at'];

    #[\Override]
    protected function casts(): array
    {
        return [
            'scheme' => IdentifierScheme::class,
            'status' => TrialLinkStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function trial(): BelongsTo
    {
        return $this->belongsTo(ClinicalTrial::class, 'clinical_trial_id');
    }

    public function linkedTrial(): BelongsTo
    {
        return $this->belongsTo(ClinicalTrial::class, 'linked_trial_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeInvolving(Builder $query, int $trialId): void
    {
        $query->where(fn (Builder $q) => $q->where('clinical_trial_id', $trialId)->orWhere('linked_trial_id', $trialId));
    }

    public function otherId(int $trialId): int
    {
        return $this->clinical_trial_id === $trialId ? $this->linked_trial_id : $this->clinical_trial_id;
    }
}
