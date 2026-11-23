<?php

declare(strict_types=1);

namespace App\Models\ClinicalTrials;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A study record read from one clinical trial registry (`source`, identified there by
 * `source_id`). When several registries describe the same study, one record is shown and the
 * others point to it through `canonical_trial_id`. Registry data, not medical advice.
 */
class ClinicalTrial extends Model
{
    use HasFactory;

    /**
     * Related lists replaced on every import, keyed by relation name.
     *
     * @var list<string>
     */
    public const array SYNCED_RELATIONS = [
        'secondaryIds',
        'collaborators',
        'references',
        'meshTerms',
        'conditions',
        'keywords',
        'locations',
        'interventions',
        'armGroups',
        'outcomes',
    ];

    protected $fillable = [
        'source',
        'source_id',
        'nct_id',
        'org_study_id',
        'brief_title',
        'official_title',
        'overall_status',
        'status_verified_date',
        'start_date',
        'primary_completion_date',
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
        'raw_format',
        'raw_document',
        'raw_url',
        'content_hash',
        'acronym',
        'responsible_party_type',
        'is_fda_regulated_drug',
        'is_fda_regulated_device',
        'has_expanded_access',
        'ipd_sharing',
        'tracked_facts',
    ];

    protected $hidden = ['raw_json', 'raw_document', 'tracked_facts', 'content_hash'];

    #[\Override]
    protected function casts(): array
    {
        return [
            'status_verified_date' => 'date:Y-m-d',
            'start_date' => 'date:Y-m-d',
            'primary_completion_date' => 'date:Y-m-d',
            'completion_date' => 'date:Y-m-d',
            'study_first_post_date' => 'date:Y-m-d',
            'last_update_post_date' => 'date:Y-m-d',
            'enrollment_count' => 'integer',
            'has_results' => 'boolean',
            'is_fda_regulated_drug' => 'boolean',
            'is_fda_regulated_device' => 'boolean',
            'has_expanded_access' => 'boolean',
            'raw_json' => 'array',
            'tracked_facts' => 'array',
        ];
    }

    /**
     * @return list<string>
     */
    public function phases(): array
    {
        return $this->phase === null || $this->phase === '' ? [] : explode(', ', $this->phase);
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(ClinicalTrialCondition::class);
    }

    public function keywords(): HasMany
    {
        return $this->hasMany(ClinicalTrialKeyword::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(ClinicalTrialLocation::class);
    }

    public function interventions(): HasMany
    {
        return $this->hasMany(ClinicalTrialIntervention::class);
    }

    public function armGroups(): HasMany
    {
        return $this->hasMany(ClinicalTrialArmGroup::class);
    }

    public function outcomes(): HasMany
    {
        return $this->hasMany(ClinicalTrialOutcome::class);
    }

    public function secondaryIds(): HasMany
    {
        return $this->hasMany(ClinicalTrialSecondaryId::class);
    }

    public function collaborators(): HasMany
    {
        return $this->hasMany(ClinicalTrialCollaborator::class);
    }

    public function references(): HasMany
    {
        return $this->hasMany(ClinicalTrialReference::class);
    }

    public function meshTerms(): HasMany
    {
        return $this->hasMany(ClinicalTrialMeshTerm::class);
    }

    public function trialChanges(): HasMany
    {
        return $this->hasMany(ClinicalTrialChange::class);
    }

    public function identifiers(): HasMany
    {
        return $this->hasMany(ClinicalTrialIdentifier::class);
    }

    /**
     * The record shown for the study when this one is a duplicate registration.
     */
    public function canonical(): BelongsTo
    {
        return $this->belongsTo(self::class, 'canonical_trial_id');
    }

    /**
     * Other registrations of this study, when this record is the one shown.
     */
    public function duplicates(): HasMany
    {
        return $this->hasMany(self::class, 'canonical_trial_id');
    }

    /**
     * The id of the record shown for this study: its own, or its canonical record's.
     */
    public function canonicalId(): int
    {
        return $this->canonical_trial_id ?? $this->id;
    }

    /**
     * Only the records shown for a study: duplicate registrations are left out.
     *
     * @param  Builder<self>  $query
     */
    public function scopeCanonical(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('canonical_trial_id'));
    }
}
