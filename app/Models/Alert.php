<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AlertFrequency;
use App\Enums\DigestFrequency;
use App\Enums\Locale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A saved search that notifies its owner when new or updated studies match it.
 *
 * A personal saved search has only a user; a team's shared saved search also has a team_id
 * (its user is the team owner who created it). Either kind can have an AI digest.
 */
class Alert extends Model
{
    use HasFactory;

    protected $attributes = [
        'ai_digest_enabled' => false,
        'ai_digest_frequency' => 'weekly',
        'ai_digest_language' => 'en',
    ];

    protected $fillable = [
        'team_id',
        'name',
        'criteria',
        'frequency',
        'notify_status_changes',
        'is_active',
        'ai_digest_enabled',
        'ai_digest_frequency',
        'ai_digest_language',
        'last_notified_at',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'criteria' => 'array',
            'frequency' => AlertFrequency::class,
            'notify_status_changes' => 'boolean',
            'is_active' => 'boolean',
            'ai_digest_enabled' => 'boolean',
            'ai_digest_frequency' => DigestFrequency::class,
            'ai_digest_language' => Locale::class,
            'last_notified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function digests(): HasMany
    {
        return $this->hasMany(Digest::class);
    }

    public function isShared(): bool
    {
        return $this->team_id !== null;
    }

    public function matches(): HasMany
    {
        return $this->hasMany(AlertMatch::class);
    }

    public function pendingMatches(): HasMany
    {
        return $this->matches()->whereNull('notified_at');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePersonal(Builder $query): void
    {
        $query->whereNull('team_id');
    }
}
