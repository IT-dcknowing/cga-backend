<?php

namespace Database\Factories;

use App\Domain\Obligations\Enums\CenterObligationKind;
use App\Domain\Obligations\Enums\CenterObligationStatus;
use App\Domain\Obligations\Models\CenterObligation;
use App\Domain\Organizations\Models\Organization;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * Obligation de test propre à un CGA : la CNPS du centre, à payer le 15 du mois prochain. Écrite hors
 * espace actif, par un contournement nommé.
 *
 * @extends Factory<CenterObligation>
 */
class CenterObligationFactory extends Factory
{
    protected $model = CenterObligation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'label' => 'CNPS du centre',
            'kind' => CenterObligationKind::Cnps,
            'due_date' => today()->addMonthNoOverflow()->day(15),
            'amount' => 120_000,
            'status' => CenterObligationStatus::ToPay,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        return Tenancy::bypass(fn () => parent::create($attributes, $parent), 'Fabrique de test : obligation du centre.');
    }
}
