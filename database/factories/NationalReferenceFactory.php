<?php

namespace Database\Factories;

use App\Domain\Referentials\Models\NationalReference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NationalReference>
 */
class NationalReferenceFactory extends Factory
{
    protected $model = NationalReference::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'year' => fake()->unique()->numberBetween(2000, 2099),
            'domestic_tax_revenue' => fake()->numberBetween(1, 9) * 1_000_000_000_000,
            'nominal_gdp' => fake()->numberBetween(10, 90) * 1_000_000_000_000,
            'tax_revenue_source' => 'Loi de finances (essai)',
            'gdp_source' => 'INS (essai)',
        ];
    }
}
