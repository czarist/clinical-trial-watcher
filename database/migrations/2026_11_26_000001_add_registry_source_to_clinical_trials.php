<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Studies can come from more than one registry: each record keeps the registry it was read
     * from (`source`) and its identifier there (`source_id`), unique per registry. Existing rows
     * are ClinicalTrials.gov records whose identifier is their NCT number, which stays the
     * lookup key for that registry but may be empty for others.
     *
     * `canonical_trial_id` points a record that describes the same study as another one (a
     * duplicate registration) at the record shown for the study; it is null for that record and
     * for studies registered once.
     */
    public function up(): void
    {
        Schema::table('clinical_trials', function (Blueprint $table): void {
            $table->string('source', 40)->default('clinicaltrials_gov')->after('id');
            $table->string('source_id', 100)->nullable()->after('source');
            $table->foreignId('canonical_trial_id')->nullable()->after('source_id')
                ->constrained('clinical_trials')->nullOnDelete();
        });

        DB::table('clinical_trials')->whereNull('source_id')->update(['source_id' => DB::raw('nct_id')]);

        Schema::table('clinical_trials', function (Blueprint $table): void {
            $table->string('source_id', 100)->nullable(false)->change();
            $table->string('nct_id')->nullable()->change();
            $table->unique(['source', 'source_id']);
        });

        Schema::table('import_runs', function (Blueprint $table): void {
            $table->string('source', 40)->default('clinicaltrials_gov')->after('id');
            $table->index(['source', 'status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::table('import_runs', function (Blueprint $table): void {
            $table->dropIndex(['source', 'status', 'started_at']);
            $table->dropColumn('source');
        });

        // Records of other registries have no NCT number and cannot stay once it is required again.
        DB::table('clinical_trials')->whereNull('nct_id')->delete();

        Schema::table('clinical_trials', function (Blueprint $table): void {
            $table->dropUnique(['source', 'source_id']);
            $table->dropConstrainedForeignId('canonical_trial_id');
            $table->dropColumn(['source', 'source_id']);
            $table->string('nct_id')->nullable(false)->change();
        });
    }
};
