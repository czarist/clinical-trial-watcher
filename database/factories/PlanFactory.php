<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
final class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'price' => 10,
            'currency' => 'usd',
            'billing_cycle' => 'monthly',
            'trial_days' => 0,
            'is_legacy' => false,
            'features' => [],
        ];
    }

    /**
     * A plan sold before the free core and AI digests (Basic, Pro, Enterprise).
     */
    public function legacy(string $name, float $price, ?string $stripePrice = null): self
    {
        return $this->state([
            'name' => $name,
            'price' => $price,
            'is_legacy' => true,
            'trial_days' => $price > 0 ? 14 : 0,
            'stripe_price_id' => $stripePrice,
            'features' => ['alerts_limit' => 50],
        ]);
    }
}
