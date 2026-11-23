<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AlertFrequency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A saved search that notifies its owner when new or updated studies match it.
 */
class Alert extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'criteria',
        'frequency',
        'notify_status_changes',
        'is_active',
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
            'last_notified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
}
