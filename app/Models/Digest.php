<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DigestFrequency;
use App\Enums\DigestGeneration;
use App\Enums\DigestLanguage;
use App\Enums\DigestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One edition of a saved search's AI digest for a reporting window.
 *
 * Personal digests have a user_id; team digests have a team_id. content is the rendered
 * edition shared by the email and the report page.
 */
class Digest extends Model
{
    protected $fillable = [
        'identity',
        'alert_id',
        'user_id',
        'team_id',
        'frequency',
        'language',
        'period_start',
        'period_end',
        'status',
        'generation',
        'fallback_reason',
        'total_updates',
        'shown_updates',
        'content',
        'metrics',
        'generated_at',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'frequency' => DigestFrequency::class,
            'language' => DigestLanguage::class,
            'status' => DigestStatus::class,
            'generation' => DigestGeneration::class,
            'period_start' => 'immutable_datetime',
            'period_end' => 'immutable_datetime',
            'total_updates' => 'integer',
            'shown_updates' => 'integer',
            'content' => 'array',
            'metrics' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(DigestItem::class)->orderBy('rank');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(DigestDelivery::class);
    }
}
