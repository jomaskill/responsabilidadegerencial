<?php

namespace Database\Factories;

use App\Models\FederativeUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FederativeUnit>
 */
class FederativeUnitFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ibge_code' => fake()->unique()->numerify('##'),
            'acronym' => fake()->unique()->regexify('[A-Z]{2}'),
            'name' => fake()->unique()->city().' Estado',
            'region' => fake()->randomElement(['Norte', 'Nordeste', 'Centro-Oeste', 'Sudeste', 'Sul']),
        ];
    }
}
