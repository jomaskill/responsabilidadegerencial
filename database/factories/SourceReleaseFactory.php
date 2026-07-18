<?php

namespace Database\Factories;

use App\Enums\ReleaseStatus;
use App\Models\DataSource;
use App\Models\SourceRelease;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SourceRelease>
 */
class SourceReleaseFactory extends Factory
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
            'reference_year' => fake()->numberBetween(2017, 2025),
            'version' => 'initial',
            'status' => ReleaseStatus::Final,
            'collected_at' => now()->toDateString(),
            'source_url' => fake()->url(),
        ];
    }
}
