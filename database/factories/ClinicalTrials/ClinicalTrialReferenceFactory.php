<?php

declare(strict_types=1);

namespace Database\Factories\ClinicalTrials;

use App\Models\ClinicalTrials\ClinicalTrial;
use App\Models\ClinicalTrials\ClinicalTrialReference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicalTrialReference>
 */
final class ClinicalTrialReferenceFactory extends Factory
{
    protected $model = ClinicalTrialReference::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinical_trial_id' => ClinicalTrial::factory(),
            'pmid' => (string) $this->faker->numberBetween(10000000, 39999999),
            'type' => 'RESULT',
            'citation' => $this->faker->sentence(12),
            'url' => null,
        ];
    }
}
