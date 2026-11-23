<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI digests.
 *
 * - change_summaries: factual summary of one change version, shared by every subscriber
 *   (cache key: change fingerprint, language and prompt version).
 * - digests: one edition per saved search and reporting window (stable identity), with the
 *   rendered content reused by the email and the report page.
 * - digest_items: the changes a digest describes, in rank order, and the summary used.
 * - digest_deliveries: one row per recipient, so a retried job never sends twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('change_summaries', function (Blueprint $table): void {
            $table->id();
            $table->string('cache_key', 64)->unique();
            $table->foreignId('clinical_trial_change_id')->constrained()->cascadeOnDelete();
            $table->string('language', 10);
            $table->string('prompt_version', 40);
            $table->string('model')->nullable();
            $table->text('summary');
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->timestamps();
        });

        Schema::create('digests', function (Blueprint $table): void {
            $table->id();
            $table->string('identity')->unique();
            $table->foreignId('alert_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('frequency');
            $table->string('language', 10);
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->string('status')->index();
            $table->string('generation')->nullable();
            $table->string('fallback_reason')->nullable();
            $table->unsignedInteger('total_updates')->default(0);
            $table->unsignedInteger('shown_updates')->default(0);
            $table->json('content')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'period_end']);
            $table->index(['team_id', 'period_end']);
        });

        Schema::create('digest_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('digest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clinical_trial_change_id')->constrained()->cascadeOnDelete();
            $table->foreignId('change_summary_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('rank');
            $table->unsignedSmallInteger('score');
            $table->string('summary_source');
            $table->timestamps();

            $table->unique(['digest_id', 'clinical_trial_change_id']);
        });

        Schema::create('digest_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('digest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['digest_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digest_deliveries');
        Schema::dropIfExists('digest_items');
        Schema::dropIfExists('digests');
        Schema::dropIfExists('change_summaries');
    }
};
