<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('clinical_trials', function (Blueprint $table) {
            $table->string('acronym')->nullable()->after('official_title');
            $table->string('responsible_party_type')->nullable()->after('sponsor_class');
            $table->boolean('is_fda_regulated_drug')->default(false)->after('responsible_party_type');
            $table->boolean('is_fda_regulated_device')->default(false)->after('is_fda_regulated_drug');
            $table->boolean('has_expanded_access')->default(false)->after('is_fda_regulated_device');
            $table->string('ipd_sharing')->nullable()->after('has_expanded_access');
        });
    }

    public function down()
    {
        Schema::table('clinical_trials', function (Blueprint $table) {
            $table->dropColumn([
                'acronym',
                'responsible_party_type',
                'is_fda_regulated_drug',
                'is_fda_regulated_device',
                'has_expanded_access',
                'ipd_sharing',
            ]);
        });
    }
};
