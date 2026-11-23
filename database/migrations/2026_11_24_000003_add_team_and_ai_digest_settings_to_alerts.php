<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved searches can belong to a team, and can have an AI digest (off for existing ones).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table): void {
            $table->foreignId('team_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('ai_digest_enabled')->default(false)->after('is_active');
            $table->string('ai_digest_frequency')->default('weekly')->after('ai_digest_enabled');
            $table->string('ai_digest_language', 10)->default('en')->after('ai_digest_frequency');

            $table->index(['ai_digest_enabled', 'ai_digest_frequency']);
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table): void {
            $table->dropIndex(['ai_digest_enabled', 'ai_digest_frequency']);
            $table->dropConstrainedForeignId('team_id');
            $table->dropColumn(['ai_digest_enabled', 'ai_digest_frequency', 'ai_digest_language']);
        });
    }
};
