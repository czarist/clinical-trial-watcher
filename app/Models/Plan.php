<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    public const string BASIC = 'Basic';

    protected $fillable = [
        'name',
        'price',
        'billing_cycle',
        'stripe_price_id',
        'trial_days',
        'features',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'trial_days' => 'integer',
            'features' => 'array',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function alertsLimit(): int
    {
        return (int) ($this->features['alerts_limit'] ?? 0);
    }

    public function isFree(): bool
    {
        return (float) $this->price === 0.0;
    }

    public function isPurchasable(): bool
    {
        return ! $this->isFree() && $this->stripe_price_id !== null;
    }
}
