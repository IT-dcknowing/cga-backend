<?php

namespace Database\Factories;

use App\Domain\Members\Models\Member;
use App\Domain\Payments\Enums\ChargeKind;
use App\Domain\Payments\Enums\ChargeStatus;
use App\Domain\Payments\Models\MemberCharge;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * Somme due de test : le droit d'adhésion d'un dossier validé, à payer. Écrite hors espace actif, par
 * un contournement nommé.
 *
 * @extends Factory<MemberCharge>
 */
class MemberChargeFactory extends Factory
{
    protected $model = MemberCharge::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'member_id' => Member::factory()->active(),
            'organization_id' => fn (array $attributes): string => (string) Tenancy::bypass(static fn () => Member::query()->whereKey($attributes['member_id'])->value('organization_id'), 'Fabrique de test : centre du dossier.'),
            'kind' => ChargeKind::EntryFee,
            'due_date' => today(),
            'amount' => 25_000,
            'amount_excl_tax' => fn (array $attributes): int => (int) $attributes['amount'] - (int) ($attributes['tax_amount'] ?? 0),
            'tax_rate' => 0,
            'tax_amount' => 0,
            'amount_paid' => 0,
            'status' => ChargeStatus::Due,
        ];
    }

    /**
     * Échéance d'honoraires du mois en cours.
     */
    public function fees(): static
    {
        return $this->state(fn (): array => [
            'kind' => ChargeKind::Fees,
            'period_start' => today()->startOfMonth(),
            'period_end' => today()->endOfMonth(),
            'due_date' => today()->startOfMonth()->addDays(9),
            'amount' => 15_000,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        return Tenancy::bypass(fn () => parent::create($attributes, $parent), 'Fabrique de test : somme due.');
    }
}
