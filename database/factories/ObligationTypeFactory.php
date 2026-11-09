<?php

namespace Database\Factories;

use App\Domain\Obligations\Enums\ObligationCategory;
use App\Domain\Obligations\Enums\ObligationKind;
use App\Domain\Obligations\Enums\ObligationPeriodicity;
use App\Domain\Obligations\Enums\TaxNature;
use App\Domain\Obligations\Models\ObligationType;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Referentials\Enums\DataStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Obligation de test du catalogue national, version `2026.1` : une déclaration mensuelle des impôts
 * due le 15 du mois suivant, pour tous les régimes. Ses valeurs sont des données d'essai, jamais le
 * catalogue de référence (chargé par fichier, RG-DATA-01).
 *
 * @extends Factory<ObligationType>
 */
class ObligationTypeFactory extends Factory
{
    protected $model = ObligationType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => null,
            'catalog_version' => '2026.1',
            'code' => 'TST-'.fake()->unique()->numerify('####'),
            'label' => 'Obligation d\'essai '.fake()->unique()->word(),
            'category' => ObligationCategory::Tax,
            'authority' => 'DGI',
            'platform' => 'e-impôts',
            'kind' => ObligationKind::Declaration,
            'periodicity' => ObligationPeriodicity::Monthly,
            'due_rule' => ['type' => 'day_of_next_month', 'day' => 15],
            'applicability' => ['regimes' => [], 'min_employees' => null, 'sectors' => []],
            'tax_nature' => TaxNature::Other,
            'has_abatement' => false,
            'creates_payment_on_done' => true,
            'active' => true,
            'data_status' => DataStatus::Provisional,
        ];
    }

    /**
     * Obligation locale d'un CGA (RG-OBL-01).
     */
    public function localTo(Organization $organization): static
    {
        return $this->state(fn (): array => ['organization_id' => $organization->id]);
    }

    /**
     * @param  array<string, mixed>  $applicability
     */
    public function applicableTo(array $applicability): static
    {
        return $this->state(fn (): array => [
            'applicability' => [...['regimes' => [], 'min_employees' => null, 'sectors' => []], ...$applicability],
        ]);
    }
}
