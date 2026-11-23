<?php

declare(strict_types=1);

namespace App\Models\ClinicalTrials;

use App\Enums\IdentifierScheme;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A normalized registry identifier carried by a study record: its own or one it cites.
 */
class ClinicalTrialIdentifier extends Model
{
    protected $fillable = ['clinical_trial_id', 'scheme', 'value', 'is_primary'];

    #[\Override]
    protected function casts(): array
    {
        return [
            'scheme' => IdentifierScheme::class,
            'is_primary' => 'boolean',
        ];
    }

    public function trial(): BelongsTo
    {
        return $this->belongsTo(ClinicalTrial::class, 'clinical_trial_id');
    }
}
