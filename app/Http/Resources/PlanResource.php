<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A plan with its price in USD per month and its paid features.
 *
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
            'currency' => strtoupper((string) $this->currency),
            'billing_cycle' => $this->billing_cycle,
            'price_label' => $this->priceLabel(),
            'trial_days' => $this->trial_days,
            'alerts_limit' => (int) config('billing.saved_search_limit'),
            'ai_digests' => $this->grantsAiDigests(),
            'team_workspace' => $this->grantsTeamWorkspace(),
            'is_free' => $this->isFree(),
            'is_legacy' => $this->is_legacy,
            'is_purchasable' => $this->isPurchasable(),
            'stripe_price_id' => $this->when($request->user()?->hasPermission('manage_plans') ?? false, $this->stripe_price_id),
        ];
    }

    /**
     * For example "$0", "$19/month" or "$99/month per team" (USD).
     */
    private function priceLabel(): string
    {
        $amount = '$'.rtrim(rtrim(number_format((float) $this->price, 2, '.', ','), '0'), '.');

        if ($this->isFree()) {
            return $amount;
        }

        return $amount.'/month'.($this->grantsTeamWorkspace() ? ' per team' : '');
    }
}
