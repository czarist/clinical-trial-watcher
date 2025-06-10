<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('clinical_trial_mesh_terms')) {
            Schema::create('clinical_trial_mesh_terms', function (Blueprint $table) {
                $table->id();
                $table->foreignId('clinical_trial_id')->constrained()->onDelete('cascade');
                $table->string('mesh_id')->nullable();
                $table->string('term');
                $table->boolean('is_primary')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('clinical_trial_references')) {
            Schema::create('clinical_trial_references', function (Blueprint $table) {
                $table->id();
                $table->foreignId('clinical_trial_id')->constrained()->onDelete('cascade');
                $table->string('pmid')->nullable();
                $table->string('type')->nullable();
                $table->text('citation')->nullable();
                $table->string('url')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('clinical_trial_collaborators')) {
            Schema::create('clinical_trial_collaborators', function (Blueprint $table) {
                $table->id();
                $table->foreignId('clinical_trial_id')->constrained()->onDelete('cascade');
                $table->string('name');
                $table->string('class')->nullable();
                $table->string('role')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('clinical_trial_secondary_ids')) {
            Schema::create('clinical_trial_secondary_ids', function (Blueprint $table) {
                $table->id();
                $table->foreignId('clinical_trial_id')
                    ->constrained()
                    ->onDelete('cascade');
                $table->string('secondary_id');
                $table->string('type')->nullable();
                $table->string('domain')->nullable();
                $table->string('link')->nullable();
                $table->timestamps();
            });

        }
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_trial_mesh_terms');
        Schema::dropIfExists('clinical_trial_references');
        Schema::dropIfExists('clinical_trial_collaborators');
        Schema::dropIfExists('clinical_trial_secondary_ids');
    }
};
