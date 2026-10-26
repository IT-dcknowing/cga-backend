<?php

namespace Database\Factories;

use App\Domain\Referentials\Models\RegionalDirectorate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegionalDirectorate>
 */
class RegionalDirectorateFactory extends Factory
{
    protected $model = RegionalDirectorate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'DR-'.fake()->unique()->numerify('####'),
            'name' => 'Direction régionale '.fake()->unique()->city(),
            'geo_key' => null,
            'active' => true,
        ];
    }
}
