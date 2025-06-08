<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_trial_interventions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinical_trial_id')->constrained()->cascadeOnDelete();
            $table->string('type')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_trial_interventions');
    }
};
