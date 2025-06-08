<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClinicalTrial extends Model
{
    use HasFactory;

    protected $fillable = [
        'nct_id',
        'org_study_id',
        'brief_title',
        'official_title',
        'overall_status',
        'status_verified_date',
        'start_date',
        'completion_date',
        'sponsor_name',
        'sponsor_class',
        'phase',
        'study_type',
        'brief_summary',
        'detailed_description',
        'eligibility_criteria',
        'min_age',
        'max_age',
        'gender',
        'enrollment_count',
        'enrollment_type',
        'study_first_post_date',
        'last_update_post_date',
        'has_results',
        'raw_json',
    ];

    public function conditions()
    {
        return $this->hasMany(ClinicalTrialCondition::class);
    }

    public function keywords()
    {
        return $this->hasMany(ClinicalTrialKeyword::class);
    }

    public function locations()
    {
        return $this->hasMany(ClinicalTrialLocation::class);
    }

    public function interventions()
    {
        return $this->hasMany(ClinicalTrialIntervention::class);
    }

    public function armGroups()
    {
        return $this->hasMany(ClinicalTrialArmGroup::class);
    }

    public function outcomes()
    {
        return $this->hasMany(ClinicalTrialOutcome::class);
    }

}
