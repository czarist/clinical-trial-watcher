<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured change tracking.
 *
 * - clinical_trial_locations.status: the site's own recruitment status.
 * - clinical_trials.tracked_facts: snapshot of the facts compared between imports (overall
 *   status, sites, key dates with their original precision, results flag). Studies imported
 *   earlier have none; their next import records the baseline.
 * - clinical_trial_changes.details: before-and-after values; fingerprint: stable identity
 *   of the change so a repeated import cannot record it twice. Existing rows keep null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinical_trial_locations', function (Blueprint $table): void {
            $table->string('status', 50)->nullable()->after('country');
        });

        Schema::table('clinical_trials', function (Blueprint $table): void {
            $table->json('tracked_facts')->nullable();
        });

        Schema::table('clinical_trial_changes', function (Blueprint $table): void {
            $table->json('details')->nullable()->after('new_status');
            $table->string('fingerprint', 64)->nullable()->unique()->after('details');
        });
    }

    public function down(): void
    {
        Schema::table('clinical_trial_changes', function (Blueprint $table): void {
            $table->dropUnique(['fingerprint']);
            $table->dropColumn(['details', 'fingerprint']);
        });

        Schema::table('clinical_trials', function (Blueprint $table): void {
            $table->dropColumn('tracked_facts');
        });

        Schema::table('clinical_trial_locations', function (Blueprint $table): void {
            $table->dropColumn('status');
        });
    }
};
