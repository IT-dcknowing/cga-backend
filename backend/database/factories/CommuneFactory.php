<?php

namespace Database\Factories;

use App\Domain\Referentials\Models\Commune;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Commune>
 */
class CommuneFactory extends Factory
{
    protected $model = Commune::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->city().' '.fake()->unique()->numerify('###'),
            'regional_directorate_id' => null,
        ];
    }
}
