<?php

declare(strict_types=1);

namespace Database\Factories\ClinicalTrials;

use App\Models\ClinicalTrials\ClinicalTrial;
use App\Models\ClinicalTrials\ClinicalTrialSecondaryId;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicalTrialSecondaryId>
 */
final class ClinicalTrialSecondaryIdFactory extends Factory
{
    protected $model = ClinicalTrialSecondaryId::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinical_trial_id' => ClinicalTrial::factory(),
            'secondary_id' => $this->faker->bothify('SEC-#####'),
            'type' => 'OTHER',
            'domain' => null,
            'link' => null,
        ];
    }
}
