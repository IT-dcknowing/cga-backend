<?php

namespace Database\Factories;

use App\Domain\Members\Models\Member;
use App\Domain\Obligations\Enums\MemberObligationSource;
use App\Domain\Obligations\Enums\MemberObligationStatus;
use App\Domain\Obligations\Models\MemberObligation;
use App\Domain\Obligations\Models\ObligationType;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * Obligation de test attribuée à un dossier validé, active depuis le début du mois. Écrite hors espace
 * actif, par un contournement nommé.
 *
 * @extends Factory<MemberObligation>
 */
class MemberObligationFactory extends Factory
{
    protected $model = MemberObligation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'member_id' => Member::factory()->active(),
            'organization_id' => fn (array $attributes): string => (string) Tenancy::bypass(static fn () => Member::query()->whereKey($attributes['member_id'])->value('organization_id'), 'Fabrique de test : centre du dossier.'),
            'obligation_type_id' => ObligationType::factory(),
            'source' => MemberObligationSource::Suggested,
            'status' => MemberObligationStatus::Active,
            'confirmed_at' => now(),
            'start_date' => today()->startOfMonth(),
        ];
    }

    public function suggested(): static
    {
        return $this->state(fn (): array => ['status' => MemberObligationStatus::Suggested, 'confirmed_at' => null]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        return Tenancy::bypass(fn () => parent::create($attributes, $parent), 'Fabrique de test : obligation attribuée.');
    }
}
