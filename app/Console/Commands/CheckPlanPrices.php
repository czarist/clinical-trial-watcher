<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Plan;
use App\Services\Billing\PlanPriceValidator;
use Illuminate\Console\Command;
use Throwable;

/**
 * Copies the Stripe Price IDs configured for Pro AI and Team AI to the plans table and checks
 * that each price charges the plan's amount in USD every month. Changes nothing in Stripe.
 */
final class CheckPlanPrices extends Command
{
    protected $signature = 'billing:check-prices {--no-sync : Only check the prices already stored on the plans}';

    protected $description = 'Link the configured Stripe prices to the paid plans and check amount, currency and interval';

    public function handle(PlanPriceValidator $validator): int
    {
        $failed = false;

        /** @var array<string, array{price: float, stripe_price: string|null}> $configured */
        $configured = (array) config('billing.plans', []);

        foreach ($configured as $name => $settings) {
            $plan = Plan::query()->where('name', $name)->first();

            if ($plan === null) {
                $this->error("{$name}: the plan does not exist; run the migrations.");
                $failed = true;

                continue;
            }

            $stripePrice = $settings['stripe_price'] ?? null;

            if (! $this->option('no-sync') && is_string($stripePrice) && $stripePrice !== '' && $plan->stripe_price_id !== $stripePrice) {
                $plan->update(['stripe_price_id' => $stripePrice]);
                $this->line("{$name}: linked to {$stripePrice}.");
            }

            try {
                $problems = $validator->problems($plan);
            } catch (Throwable $exception) {
                $problems = ['Stripe could not be read: '.$exception->getMessage()];
            }

            if ($problems === []) {
                $this->info("{$name}: {$plan->stripe_price_id} charges \${$plan->price} USD per month.");

                continue;
            }

            $failed = true;
            $this->error("{$name}: ".implode('; ', $problems).'.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
