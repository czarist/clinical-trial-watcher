<?php

declare(strict_types=1);

namespace App\Models\ClinicalTrials;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClinicalTrialReference extends Model
{
    use HasFactory;

    private const string PUBMED_URL = 'https://pubmed.ncbi.nlm.nih.gov/%s/';

    public $timestamps = true;

    protected $fillable = [
        'clinical_trial_id',
        'pmid',
        'type',
        'citation',
        'url',
    ];

    protected $appends = ['pubmed_url'];

    public function trial(): BelongsTo
    {
        return $this->belongsTo(ClinicalTrial::class, 'clinical_trial_id');
    }

    /**
     * Link to the PubMed record when the registry gives a numeric PMID.
     */
    protected function pubmedUrl(): Attribute
    {
        return Attribute::get(
            fn (): ?string => is_string($this->pmid) && ctype_digit($this->pmid)
                ? sprintf(self::PUBMED_URL, $this->pmid)
                : null,
        );
    }
}
