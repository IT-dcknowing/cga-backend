<?php

namespace Database\Factories;

use App\Domain\Referentials\Models\RegionalDirectorate;
use App\Domain\Referentials\Models\TaxOffice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxOffice>
 */
class TaxOfficeFactory extends Factory
{
    protected $model = TaxOffice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'regional_directorate_id' => RegionalDirectorate::factory(),
            'code' => 'CDI-'.fake()->unique()->numerify('####'),
            'name' => 'CDI '.fake()->unique()->city(),
            'active' => true,
        ];
    }
}
