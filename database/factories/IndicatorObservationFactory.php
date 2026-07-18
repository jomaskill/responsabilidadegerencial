<?php

namespace Database\Factories;

use App\Enums\AvailabilityStatus;
use App\Enums\QualityStatus;
use App\Models\IndicatorObservation;
use App\Models\IndicatorVersion;
use App\Models\Municipality;
use App\Models\ProcessingRun;
use App\Models\SourceRelease;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IndicatorObservation>
 */
class IndicatorObservationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'observation_key' => hash('sha256', fake()->unique()->uuid()),
            'municipality_id' => Municipality::factory(),
            'indicator_version_id' => IndicatorVersion::factory(),
            'source_release_id' => SourceRelease::factory(),
            'processing_run_id' => ProcessingRun::factory(),
            'reference_year' => fake()->numberBetween(2017, 2025),
            'value' => fake()->randomFloat(4, 0, 1000),
            'availability_status' => AvailabilityStatus::Available,
            'quality_status' => QualityStatus::Accepted,
        ];
    }
}
