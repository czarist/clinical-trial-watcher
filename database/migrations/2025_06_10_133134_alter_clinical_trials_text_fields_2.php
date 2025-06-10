<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE clinical_trials MODIFY brief_title TEXT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE clinical_trials MODIFY brief_title VARCHAR(255) NOT NULL');
    }
};
