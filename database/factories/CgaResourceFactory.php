<?php

namespace Database\Factories;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Reports\Enums\CgaResourceCategory;
use App\Domain\Reports\Models\CgaResource;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * Moyens matériels de test d'un CGA (ordinateurs). Écrits hors espace actif, par un contournement
 * nommé.
 *
 * @extends Factory<CgaResource>
 */
class CgaResourceFactory extends Factory
{
    protected $model = CgaResource::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'category' => CgaResourceCategory::Computers,
            'quantity' => 6,
            'description' => 'Postes de travail',
            'need' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        return Tenancy::bypass(fn () => parent::create($attributes, $parent), 'Fabrique de test : moyens matériels.');
    }
}
