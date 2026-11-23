<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->index();
            $table->date('updated_since')->nullable();
            $table->unsignedInteger('pages')->default(0);
            $table->unsignedInteger('studies_created')->default(0);
            $table->unsignedInteger('studies_updated')->default(0);
            $table->unsignedInteger('studies_unchanged')->default(0);
            $table->unsignedInteger('studies_failed')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('clinical_trial_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('clinical_trial_id')->constrained()->cascadeOnDelete();
            $table->foreignId('import_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('previous_status')->nullable();
            $table->string('new_status')->nullable();
            $table->timestamps();

            $table->index(['import_run_id', 'type']);
            $table->index(['clinical_trial_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_trial_changes');
        Schema::dropIfExists('import_runs');
    }
};
