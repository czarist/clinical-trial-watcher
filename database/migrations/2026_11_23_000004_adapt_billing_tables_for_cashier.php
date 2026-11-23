<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adapts the existing billing tables to the schema Laravel Cashier (Stripe) expects.
 * Existing rows are kept: payment_provider_id becomes stripe_id and status becomes stripe_status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('stripe_id')->nullable()->index()->after('plan_id');
            $table->string('pm_type')->nullable()->after('stripe_id');
            $table->string('pm_last_four', 4)->nullable()->after('pm_type');
        });

        Schema::table('plans', function (Blueprint $table): void {
            $table->string('stripe_price_id')->nullable()->unique()->after('billing_cycle');
            $table->unsignedSmallInteger('trial_days')->default(0)->after('stripe_price_id');
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropForeign(['plan_id']);
            $table->renameColumn('payment_provider_id', 'stripe_id');
            $table->renameColumn('status', 'stripe_status');
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->foreignId('plan_id')->nullable()->change();
            $table->foreign('plan_id')->references('id')->on('plans')->nullOnDelete();
            $table->dropColumn('payment_provider');
            $table->string('type')->default('default')->after('plan_id');
            $table->string('stripe_price')->nullable()->after('stripe_status');
            $table->integer('quantity')->nullable()->after('stripe_price');
            $table->unique('stripe_id');
            $table->index(['user_id', 'stripe_status']);
        });

        Schema::create('subscription_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id');
            $table->string('stripe_id')->unique();
            $table->string('stripe_product');
            $table->string('stripe_price');
            $table->string('meter_id')->nullable();
            $table->integer('quantity')->nullable();
            $table->string('meter_event_name')->nullable();
            $table->timestamps();

            $table->index(['subscription_id', 'stripe_price']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_items');

        Schema::table('subscriptions', function (Blueprint $table): void {
            // MySQL may serve the user_id foreign key with the composite index, so the key is recreated.
            $table->dropForeign(['plan_id']);
            $table->dropForeign(['user_id']);
            $table->dropIndex(['user_id', 'stripe_status']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->dropUnique(['stripe_id']);
            $table->dropColumn(['type', 'stripe_price', 'quantity']);
            $table->string('payment_provider')->nullable()->after('plan_id');
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->renameColumn('stripe_id', 'payment_provider_id');
            $table->renameColumn('stripe_status', 'status');
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->foreign('plan_id')->references('id')->on('plans')->cascadeOnDelete();
        });

        Schema::table('plans', function (Blueprint $table): void {
            $table->dropUnique(['stripe_price_id']);
            $table->dropColumn(['stripe_price_id', 'trial_days']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['stripe_id']);
            $table->dropColumn(['stripe_id', 'pm_type', 'pm_last_four']);
        });
    }
};
