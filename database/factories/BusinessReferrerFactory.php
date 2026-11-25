<?php

namespace Database\Factories;

use App\Domain\Members\Models\BusinessReferrer;
use App\Domain\Organizations\Models\Organization;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * Apporteur d'affaires de test (RG-PTF-06), écrit hors espace actif par un contournement nommé.
 *
 * @extends Factory<BusinessReferrer>
 */
class BusinessReferrerFactory extends Factory
{
    protected $model = BusinessReferrer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'label' => 'Accessoire '.fake()->unique()->numberBetween(1, 9999),
            'name' => fake()->name(),
            'phone' => '+22507'.fake()->numerify('########'),
            'active' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        return Tenancy::bypass(fn () => parent::create($attributes, $parent), 'Fabrique de test : apporteur d\'affaires.');
    }
}
