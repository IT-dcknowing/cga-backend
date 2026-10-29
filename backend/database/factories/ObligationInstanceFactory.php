<?php

namespace Database\Factories;

use App\Domain\Obligations\Enums\InstanceKind;
use App\Domain\Obligations\Enums\InstanceStatus;
use App\Domain\Obligations\Enums\ObligationCategory;
use App\Domain\Obligations\Models\MemberObligation;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * Échéance de test : la déclaration du mois en cours d'une obligation attribuée. Écrite hors espace
 * actif, par un contournement nommé.
 *
 * @extends Factory<ObligationInstance>
 */
class ObligationInstanceFactory extends Factory
{
    protected $model = ObligationInstance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'member_obligation_id' => MemberObligation::factory(),
            'member_id' => fn (array $attributes): string => (string) $this->obligation($attributes)->member_id,
            'organization_id' => fn (array $attributes): string => (string) $this->obligation($attributes)->organization_id,
            'obligation_type_id' => fn (array $attributes): string => (string) $this->obligation($attributes)->obligation_type_id,
            'label' => 'Déclaration d\'essai',
            'category' => ObligationCategory::Tax,
            'kind' => InstanceKind::Declaration,
            'period_label' => today()->format('Y-m'),
            'period_start' => today()->startOfMonth(),
            'period_end' => today()->endOfMonth(),
            'due_date' => today()->addMonthNoOverflow()->day(15),
            'status' => InstanceStatus::Todo,
            'amount_paid' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function obligation(array $attributes): MemberObligation
    {
        return Tenancy::bypass(static fn (): MemberObligation => MemberObligation::query()->findOrFail($attributes['member_obligation_id']), 'Fabrique de test : obligation de l\'échéance.');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        return Tenancy::bypass(fn () => parent::create($attributes, $parent), 'Fabrique de test : échéance.');
    }
}
