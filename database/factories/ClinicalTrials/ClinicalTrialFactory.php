<?php

declare(strict_types=1);

namespace Database\Factories\ClinicalTrials;

use App\Enums\StudyPhase;
use App\Enums\StudyStatus;
use App\Enums\StudyType;
use App\Models\ClinicalTrials\ClinicalTrial;
use App\Services\Registries\ClinicalTrialsGovSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicalTrial>
 */
final class ClinicalTrialFactory extends Factory
{
    protected $model = ClinicalTrial::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = $this->faker->dateTimeBetween('-5 years', '+1 year');

        return [
            'source' => ClinicalTrialsGovSource::KEY,
            'nct_id' => sprintf('NCT%08d', $this->faker->unique()->numberBetween(1, 99_999_999)),
            // A ClinicalTrials.gov record is identified by its NCT number, also when a test sets it.
            'source_id' => fn (array $attributes): ?string => $attributes['nct_id'],
            'org_study_id' => $this->faker->bothify('ORG-####-??'),
            'brief_title' => $this->faker->sentence(8),
            'official_title' => $this->faker->sentence(14),
            'overall_status' => $this->faker->randomElement([StudyStatus::Recruiting, StudyStatus::Completed, StudyStatus::NotYetRecruiting])->value,
            'start_date' => $start->format('Y-m-d'),
            'primary_completion_date' => (clone $start)->modify('+2 years')->format('Y-m-d'),
            'completion_date' => (clone $start)->modify('+3 years')->format('Y-m-d'),
            'study_first_post_date' => (clone $start)->modify('-2 months')->format('Y-m-d'),
            'last_update_post_date' => $this->faker->dateTimeBetween('-1 year')->format('Y-m-d'),
            'sponsor_name' => $this->faker->company(),
            'sponsor_class' => 'INDUSTRY',
            'phase' => StudyPhase::Phase2->value,
            'study_type' => StudyType::Interventional->value,
            'brief_summary' => $this->faker->paragraph(),
            'enrollment_count' => $this->faker->numberBetween(10, 2000),
            'enrollment_type' => 'ESTIMATED',
            'has_results' => false,
            'is_fda_regulated_drug' => false,
            'is_fda_regulated_device' => false,
            'has_expanded_access' => false,
        ];
    }

    public function status(StudyStatus $status): self
    {
        return $this->state(['overall_status' => $status->value]);
    }

    /**
     * @param  list<StudyPhase>  $phases
     */
    public function phases(array $phases): self
    {
        return $this->state(['phase' => implode(', ', array_map(fn (StudyPhase $p): string => $p->value, $phases))]);
    }
}
