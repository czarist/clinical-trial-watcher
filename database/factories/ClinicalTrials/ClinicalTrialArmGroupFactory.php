<?php

declare(strict_types=1);

namespace Database\Factories\ClinicalTrials;

use App\Models\ClinicalTrials\ClinicalTrial;
use App\Models\ClinicalTrials\ClinicalTrialArmGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicalTrialArmGroup>
 */
final class ClinicalTrialArmGroupFactory extends Factory
{
    protected $model = ClinicalTrialArmGroup::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinical_trial_id' => ClinicalTrial::factory(),
            'label' => 'Arm '.$this->faker->randomLetter(),
            'type' => 'EXPERIMENTAL',
            'description' => $this->faker->sentence(),
        ];
    }
}
