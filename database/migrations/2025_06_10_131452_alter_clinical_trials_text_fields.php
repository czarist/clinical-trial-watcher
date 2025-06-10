<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AlterClinicalTrialsTextFields extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE clinical_trials MODIFY official_title TEXT NULL');
        DB::statement('ALTER TABLE clinical_trials MODIFY brief_summary TEXT NULL');
        DB::statement('ALTER TABLE clinical_trials MODIFY detailed_description LONGTEXT NULL');
        DB::statement('ALTER TABLE clinical_trials MODIFY eligibility_criteria LONGTEXT NULL');
        DB::statement('ALTER TABLE clinical_trials MODIFY raw_json LONGTEXT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE clinical_trials MODIFY official_title VARCHAR(255) NOT NULL');
        DB::statement('ALTER TABLE clinical_trials MODIFY brief_summary VARCHAR(255) NULL');
        DB::statement('ALTER TABLE clinical_trials MODIFY detailed_description TEXT NULL');
        DB::statement('ALTER TABLE clinical_trials MODIFY eligibility_criteria TEXT NULL');
        DB::statement('ALTER TABLE clinical_trials MODIFY raw_json TEXT NULL');
    }
}
