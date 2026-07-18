<?php

namespace Database\Factories;

use App\Models\Indicator;
use App\Models\IndicatorVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IndicatorVersion>
 */
class IndicatorVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'indicator_id' => Indicator::factory(),
            'version' => 1,
            'valid_from' => '2017-01-01',
        ];
    }
}
