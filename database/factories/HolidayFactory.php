<?php

namespace Database\Factories;

use App\Domain\Referentials\Models\Holiday;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Holiday>
 */
class HolidayFactory extends Factory
{
    protected $model = Holiday::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'date' => fake()->unique()->dateTimeBetween('2030-01-01', '2039-12-31')->format('Y-m-d'),
            'label' => 'Jour férié '.fake()->word(),
        ];
    }
}
