<?php

declare(strict_types=1);

namespace Database\Factories\ClinicalTrials;

use App\Models\ClinicalTrials\ClinicalTrial;
use App\Models\ClinicalTrials\ClinicalTrialCondition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicalTrialCondition>
 */
final class ClinicalTrialConditionFactory extends Factory
{
    protected $model = ClinicalTrialCondition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinical_trial_id' => ClinicalTrial::factory(),
            'condition' => $this->faker->randomElement(['Breast Cancer', 'Type 2 Diabetes', 'Asthma', 'Alzheimer Disease']),
        ];
    }
}
