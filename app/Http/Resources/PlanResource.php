<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Plan
 */
final class PlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'price' => $this->price,
            'billing_cycle' => $this->billing_cycle,
            'trial_days' => $this->trial_days,
            'alerts_limit' => $this->alertsLimit(),
            'is_free' => $this->isFree(),
            'is_purchasable' => $this->isPurchasable(),
            'stripe_price_id' => $this->when($request->user()?->hasPermission('manage_plans') ?? false, $this->stripe_price_id),
        ];
    }
}
