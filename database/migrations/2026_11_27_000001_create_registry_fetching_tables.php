<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support for registries read from downloads and web pages:
 * - registry_pages: the last response of every URL fetched from a registry, with its
 *   validators for conditional requests and the path of its stored body, so a parser fix
 *   can run again without fetching;
 * - the raw page or file of each record and a hash of its normalized content;
 * - the share of mapped fields filled in a run, to detect layout changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registry_pages', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 64);
            $table->char('url_hash', 64);
            $table->text('url');
            $table->unsignedSmallInteger('status');
            $table->string('etag')->nullable();
            $table->string('last_modified')->nullable();
            $table->string('content_type')->nullable();
            $table->char('content_hash', 64);
            $table->unsignedBigInteger('size');
            $table->string('storage_path');
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['source', 'url_hash']);
        });

        Schema::table('clinical_trials', function (Blueprint $table): void {
            $table->char('content_hash', 64)->nullable()->after('tracked_facts');
            $table->string('raw_format', 16)->nullable()->after('raw_json');
            $table->longText('raw_document')->nullable()->after('raw_format');
            $table->string('raw_url', 2048)->nullable()->after('raw_document');
        });

        Schema::table('import_runs', function (Blueprint $table): void {
            $table->decimal('field_fill_ratio', 5, 4)->nullable()->after('studies_failed');
        });
    }

    public function down(): void
    {
        Schema::table('import_runs', function (Blueprint $table): void {
            $table->dropColumn('field_fill_ratio');
        });

        Schema::table('clinical_trials', function (Blueprint $table): void {
            $table->dropColumn(['content_hash', 'raw_format', 'raw_document', 'raw_url']);
        });

        Schema::dropIfExists('registry_pages');
    }
};
