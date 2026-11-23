<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('criteria');
            $table->string('frequency')->default('daily');
            $table->boolean('notify_status_changes')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'frequency']);
        });

        Schema::create('alert_matches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('alert_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clinical_trial_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clinical_trial_change_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason');
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->index(['alert_id', 'notified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_matches');
        Schema::dropIfExists('alerts');
    }
};
