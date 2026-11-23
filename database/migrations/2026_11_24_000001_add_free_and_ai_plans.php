<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Free core with paid AI digests.
 *
 * Adds the plan currency and a legacy flag, marks Basic, Pro and Enterprise as legacy plans
 * (their prices, Stripe price IDs and subscriptions are left as they are) and creates the
 * Free, Pro AI and Team AI plans. Safe to run on a database where the plans already exist.
 */
return new class extends Migration
{
    private const array LEGACY = ['Basic', 'Pro', 'Enterprise'];

    public function up(): void
    {
        if (! Schema::hasColumn('plans', 'currency')) {
            Schema::table('plans', function (Blueprint $table): void {
                $table->string('currency', 3)->default('usd')->after('price');
                $table->boolean('is_legacy')->default(false)->after('trial_days');
            });
        }

        DB::table('plans')->whereIn('name', self::LEGACY)->update(['is_legacy' => true]);

        $now = now();
        $plans = [
            ['name' => 'Free', 'price' => 0, 'features' => ['ai_digests' => false, 'team_workspace' => false]],
            ['name' => 'Pro AI', 'price' => 19, 'features' => ['ai_digests' => true, 'team_workspace' => false]],
            ['name' => 'Team AI', 'price' => 99, 'features' => ['ai_digests' => true, 'team_workspace' => true]],
        ];

        foreach ($plans as $plan) {
            if (DB::table('plans')->where('name', $plan['name'])->exists()) {
                continue;
            }

            DB::table('plans')->insert([
                'name' => $plan['name'],
                'price' => $plan['price'],
                'currency' => 'usd',
                'billing_cycle' => 'monthly',
                'trial_days' => 0,
                'is_legacy' => false,
                'features' => json_encode($plan['features'], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // The new plans are removed only when no user or subscription references them.
        DB::table('plans')
            ->whereIn('name', ['Free', 'Pro AI', 'Team AI'])
            ->whereNotIn('id', DB::table('users')->whereNotNull('plan_id')->select('plan_id'))
            ->whereNotIn('id', DB::table('subscriptions')->whereNotNull('plan_id')->select('plan_id'))
            ->delete();

        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn(['currency', 'is_legacy']);
        });
    }
};
