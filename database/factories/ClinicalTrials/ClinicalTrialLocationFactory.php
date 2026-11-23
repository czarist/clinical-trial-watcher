<?php

declare(strict_types=1);

namespace Database\Factories\ClinicalTrials;

use App\Models\ClinicalTrials\ClinicalTrial;
use App\Models\ClinicalTrials\ClinicalTrialLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicalTrialLocation>
 */
final class ClinicalTrialLocationFactory extends Factory
{
    protected $model = ClinicalTrialLocation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinical_trial_id' => ClinicalTrial::factory(),
            'facility' => $this->faker->company().' Hospital',
            'city' => $this->faker->city(),
            'state' => $this->faker->state(),
            'country' => $this->faker->country(),
            'zip' => $this->faker->postcode(),
            'latitude' => $this->faker->latitude(),
            'longitude' => $this->faker->longitude(),
        ];
    }
}
