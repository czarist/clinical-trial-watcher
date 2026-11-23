<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AlertFrequency;
use App\Models\Alert;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Alert>
 */
final class AlertFactory extends Factory
{
    protected $model = Alert::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->words(3, true),
            'criteria' => ['condition' => 'cancer'],
            'frequency' => AlertFrequency::Daily,
            'notify_status_changes' => true,
            'is_active' => true,
        ];
    }

    public function instant(): self
    {
        return $this->state(['frequency' => AlertFrequency::Instant]);
    }
}
