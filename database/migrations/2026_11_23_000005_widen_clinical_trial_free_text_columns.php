<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Free-text values from the registry can exceed 255 characters; a longer value made the
 * whole study fail to import. Indexed columns stay as strings and are truncated by the importer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinical_trial_outcomes', function (Blueprint $table): void {
            $table->text('measure')->change();
            $table->text('time_frame')->nullable()->change();
        });

        Schema::table('clinical_trial_interventions', function (Blueprint $table): void {
            $table->text('name')->change();
        });

        Schema::table('clinical_trial_arm_groups', function (Blueprint $table): void {
            $table->text('label')->change();
        });

        Schema::table('clinical_trial_locations', function (Blueprint $table): void {
            $table->text('facility')->nullable()->change();
        });

        Schema::table('clinical_trial_references', function (Blueprint $table): void {
            $table->text('url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('clinical_trial_references', function (Blueprint $table): void {
            $table->string('url')->nullable()->change();
        });

        Schema::table('clinical_trial_locations', function (Blueprint $table): void {
            $table->string('facility')->nullable()->change();
        });

        Schema::table('clinical_trial_arm_groups', function (Blueprint $table): void {
            $table->string('label')->change();
        });

        Schema::table('clinical_trial_interventions', function (Blueprint $table): void {
            $table->string('name')->change();
        });

        Schema::table('clinical_trial_outcomes', function (Blueprint $table): void {
            $table->string('measure')->change();
            $table->string('time_frame')->nullable()->change();
        });
    }
};
