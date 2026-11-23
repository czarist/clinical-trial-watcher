<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A subscription plan. Prices are in USD per month.
 *
 * Free, Pro AI and Team AI are offered. Legacy plans (Basic, Pro, Enterprise) remain for
 * existing subscriptions but are not sold and grant no AI features.
 */
class Plan extends Model
{
    use HasFactory;

    public const string FREE = 'Free';

    public const string PRO_AI = 'Pro AI';

    public const string TEAM_AI = 'Team AI';

    protected $fillable = [
        'name',
        'price',
        'currency',
        'billing_cycle',
        'stripe_price_id',
        'trial_days',
        'is_legacy',
        'features',
    ];

    protected $attributes = [
        'currency' => 'usd',
        'is_legacy' => false,
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'trial_days' => 'integer',
            'is_legacy' => 'boolean',
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

    public function isFree(): bool
    {
        return (float) $this->price === 0.0;
    }

    /**
     * Offered at checkout: paid, current and linked to a Stripe price.
     */
    public function isPurchasable(): bool
    {
        return ! $this->isFree() && ! $this->is_legacy && $this->stripe_price_id !== null;
    }

    public function grantsAiDigests(): bool
    {
        return ! $this->is_legacy && (bool) ($this->features['ai_digests'] ?? false);
    }

    public function grantsTeamWorkspace(): bool
    {
        return ! $this->is_legacy && (bool) ($this->features['team_workspace'] ?? false);
    }

    /**
     * Price in cents, as Stripe stores it.
     */
    public function priceInCents(): int
    {
        return (int) round((float) $this->price * 100);
    }
}
