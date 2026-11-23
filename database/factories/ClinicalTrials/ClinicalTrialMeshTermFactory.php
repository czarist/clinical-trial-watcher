<?php

declare(strict_types=1);

namespace Database\Factories\ClinicalTrials;

use App\Models\ClinicalTrials\ClinicalTrial;
use App\Models\ClinicalTrials\ClinicalTrialMeshTerm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicalTrialMeshTerm>
 */
final class ClinicalTrialMeshTermFactory extends Factory
{
    protected $model = ClinicalTrialMeshTerm::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinical_trial_id' => ClinicalTrial::factory(),
            'mesh_id' => $this->faker->bothify('D######'),
            'term' => $this->faker->randomElement(['Neoplasms', 'Diabetes Mellitus', 'Asthma']),
            'is_primary' => true,
        ];
    }
}
