<?php

declare(strict_types=1);

namespace Database\Factories\ClinicalTrials;

use App\Models\ClinicalTrials\ClinicalTrial;
use App\Models\ClinicalTrials\ClinicalTrialOutcome;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicalTrialOutcome>
 */
final class ClinicalTrialOutcomeFactory extends Factory
{
    protected $model = ClinicalTrialOutcome::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinical_trial_id' => ClinicalTrial::factory(),
            'type' => 'PRIMARY',
            'measure' => $this->faker->sentence(6),
            'description' => $this->faker->sentence(),
            'time_frame' => '12 weeks',
        ];
    }
}
