<?php

declare(strict_types=1);

namespace Database\Factories\ClinicalTrials;

use App\Models\ClinicalTrials\ClinicalTrial;
use App\Models\ClinicalTrials\ClinicalTrialCollaborator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicalTrialCollaborator>
 */
final class ClinicalTrialCollaboratorFactory extends Factory
{
    protected $model = ClinicalTrialCollaborator::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinical_trial_id' => ClinicalTrial::factory(),
            'name' => $this->faker->company(),
            'class' => 'OTHER',
        ];
    }
}
