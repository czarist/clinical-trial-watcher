<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\Plan;

final class PlanAdministration
{
    /**
     * @param  array{name: string, price: float|string, billing_cycle: string, stripe_price_id?: string|null, trial_days?: int, alerts_limit: int}  $data
     */
    public function create(array $data): Plan
    {
        return Plan::create($this->attributes($data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Plan $plan, array $data): Plan
    {
        $plan->update($this->attributes($data, $plan));

        return $plan;
    }

    public function delete(Plan $plan): void
    {
        $plan->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?Plan $plan = null): array
    {
        $attributes = array_intersect_key($data, array_flip(['name', 'price', 'billing_cycle', 'stripe_price_id', 'trial_days']));

        if (array_key_exists('alerts_limit', $data)) {
            $attributes['features'] = [...($plan->features ?? []), 'alerts_limit' => (int) $data['alerts_limit']];
        }

        return $attributes;
    }
}
