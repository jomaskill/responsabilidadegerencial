<?php

namespace Database\Factories;

use App\Enums\ProcessingStatus;
use App\Models\DataSource;
use App\Models\ProcessingRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcessingRun>
 */
class ProcessingRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'data_source_id' => DataSource::factory(),
            'type' => 'import',
            'status' => ProcessingStatus::Running,
            'started_at' => now(),
        ];
    }
}
