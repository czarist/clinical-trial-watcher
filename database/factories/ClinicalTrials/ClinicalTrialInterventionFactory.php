<?php

declare(strict_types=1);

namespace Database\Factories\ClinicalTrials;

use App\Models\ClinicalTrials\ClinicalTrial;
use App\Models\ClinicalTrials\ClinicalTrialIntervention;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicalTrialIntervention>
 */
final class ClinicalTrialInterventionFactory extends Factory
{
    protected $model = ClinicalTrialIntervention::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinical_trial_id' => ClinicalTrial::factory(),
            'type' => 'DRUG',
            'name' => $this->faker->word().'mab',
            'description' => $this->faker->sentence(),
        ];
    }
}
