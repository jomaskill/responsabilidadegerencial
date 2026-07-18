<?php

namespace Database\Factories;

use App\Enums\IndicatorDirection;
use App\Enums\Periodicity;
use App\Models\Indicator;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Indicator>
 */
class IndicatorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word().' '.fake()->word().' '.fake()->word();

        return [
            'slug' => Str::slug($name),
            'name' => Str::title($name),
            'description' => fake()->sentence(),
            'theme' => fake()->randomElement(['demografia', 'economia', 'seguranca', 'saneamento', 'educacao']),
            'unit' => fake()->randomElement(['pessoas', 'R$', '%', 'por_100_mil']),
            'direction' => IndicatorDirection::ContextOnly,
            'periodicity' => Periodicity::Annual,
            'aggregation_method' => 'value',
            'is_derived' => false,
            'is_active' => true,
        ];
    }
}
