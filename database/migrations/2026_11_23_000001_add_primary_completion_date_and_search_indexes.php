<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinical_trials', function (Blueprint $table): void {
            $table->date('primary_completion_date')->nullable()->after('start_date');

            $table->index('overall_status');
            $table->index('phase');
            $table->index('study_type');
            $table->index('last_update_post_date');
            $table->index('start_date');
            $table->index('sponsor_name');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('clinical_trials', function (Blueprint $table): void {
                $table->fullText(['brief_title', 'official_title', 'brief_summary'], 'clinical_trials_fulltext');
            });
        }

        Schema::table('clinical_trial_conditions', function (Blueprint $table): void {
            $table->index('condition');
        });

        Schema::table('clinical_trial_keywords', function (Blueprint $table): void {
            $table->index('keyword');
        });

        Schema::table('clinical_trial_locations', function (Blueprint $table): void {
            $table->index('country');
            $table->index('city');
        });

        Schema::table('clinical_trial_mesh_terms', function (Blueprint $table): void {
            $table->index('term');
        });
    }

    public function down(): void
    {
        Schema::table('clinical_trial_mesh_terms', function (Blueprint $table): void {
            $table->dropIndex(['term']);
        });

        Schema::table('clinical_trial_locations', function (Blueprint $table): void {
            $table->dropIndex(['country']);
            $table->dropIndex(['city']);
        });

        Schema::table('clinical_trial_keywords', function (Blueprint $table): void {
            $table->dropIndex(['keyword']);
        });

        Schema::table('clinical_trial_conditions', function (Blueprint $table): void {
            $table->dropIndex(['condition']);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('clinical_trials', function (Blueprint $table): void {
                $table->dropFullText('clinical_trials_fulltext');
            });
        }

        Schema::table('clinical_trials', function (Blueprint $table): void {
            $table->dropIndex(['overall_status']);
            $table->dropIndex(['phase']);
            $table->dropIndex(['study_type']);
            $table->dropIndex(['last_update_post_date']);
            $table->dropIndex(['start_date']);
            $table->dropIndex(['sponsor_name']);
            $table->dropColumn('primary_completion_date');
        });
    }
};
