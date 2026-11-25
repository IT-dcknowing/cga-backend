<?php

namespace Database\Factories;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Reports\Enums\CgaPersonKind;
use App\Domain\Reports\Models\CgaPerson;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * Dirigeant de test de la fiche permanente d'un CGA. Écrit hors espace actif, par un contournement
 * nommé.
 *
 * @extends Factory<CgaPerson>
 */
class CgaPersonFactory extends Factory
{
    protected $model = CgaPerson::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'kind' => CgaPersonKind::Director,
            'full_name' => fake()->name(),
            'nationality' => 'Ivoirienne',
            'profession' => 'Expert-comptable',
            'diplomas' => 'DECOFI',
            'function_in_cga' => 'Gérant',
            'start_date' => '2020-01-15',
            'practice_structure' => 'Cabinet fictif',
            'practice_function' => 'Associé',
            'practice_years' => 12,
            'cnps_declared' => true,
            'active' => true,
        ];
    }

    public function kind(CgaPersonKind $kind): static
    {
        return $this->state(['kind' => $kind]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        return Tenancy::bypass(fn () => parent::create($attributes, $parent), 'Fabrique de test : personne de la fiche permanente.');
    }
}
