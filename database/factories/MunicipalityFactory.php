<?php

namespace Database\Factories;

use App\Models\FederativeUnit;
use App\Models\Municipality;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Municipality>
 */
class MunicipalityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->city();

        return [
            'federative_unit_id' => FederativeUnit::factory(),
            'ibge_code' => fake()->unique()->numerify('#######'),
            'name' => $name,
            'normalized_name' => Str::lower(Str::ascii($name)),
            'is_active' => true,
        ];
    }
}
