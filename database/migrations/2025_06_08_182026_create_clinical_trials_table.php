<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_trials', function (Blueprint $table) {
            $table->id();
            $table->string('nct_id')->unique();
            $table->string('org_study_id')->nullable();
            $table->string('brief_title');
            $table->string('official_title')->nullable();
            $table->string('overall_status')->nullable();
            $table->date('status_verified_date')->nullable();
            $table->date('start_date')->nullable();
            $table->date('completion_date')->nullable();
            $table->string('sponsor_name')->nullable();
            $table->string('sponsor_class')->nullable();
            $table->string('phase')->nullable();
            $table->string('study_type')->nullable();
            $table->text('brief_summary')->nullable();
            $table->longText('detailed_description')->nullable();
            $table->longText('eligibility_criteria')->nullable();
            $table->string('min_age')->nullable();
            $table->string('max_age')->nullable();
            $table->string('gender')->nullable();
            $table->integer('enrollment_count')->nullable();
            $table->string('enrollment_type')->nullable();
            $table->date('study_first_post_date')->nullable();
            $table->date('last_update_post_date')->nullable();
            $table->boolean('has_results')->default(false);
            $table->longText('raw_json')->nullable(); // guarda payload completo se quiser
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_trials');
    }
};
