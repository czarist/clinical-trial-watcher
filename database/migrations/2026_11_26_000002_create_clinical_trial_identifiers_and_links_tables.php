<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Normalized registry identifiers of each record (its own and the ones it cites), and the
     * links between records that share one. Links are how duplicate registrations of a study
     * are found; a person can confirm or reject them, and that decision survives re-imports.
     */
    public function up(): void
    {
        Schema::create('clinical_trial_identifiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('clinical_trial_id')->constrained()->cascadeOnDelete();
            $table->string('scheme', 20);
            $table->string('value', 100);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['clinical_trial_id', 'scheme', 'value']);
            $table->index(['scheme', 'value']);
        });

        Schema::create('clinical_trial_links', function (Blueprint $table): void {
            $table->id();
            // The pair is stored once, lower id first.
            $table->foreignId('clinical_trial_id')->constrained()->cascadeOnDelete();
            $table->foreignId('linked_trial_id')->constrained('clinical_trials')->cascadeOnDelete();
            $table->string('scheme', 20);
            $table->string('value', 100);
            $table->string('status', 20)->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['clinical_trial_id', 'linked_trial_id']);
            $table->index('linked_trial_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_trial_links');
        Schema::dropIfExists('clinical_trial_identifiers');
    }
};
