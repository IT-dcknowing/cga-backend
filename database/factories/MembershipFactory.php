<?php

namespace Database\Factories;

use App\Domain\Members\Models\Member;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Membership>
 */
class MembershipFactory extends Factory
{
    protected $model = Membership::class;

    /**
     * Un comptable actif d'un CGA par défaut.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'organization_id' => Organization::factory()->cga(),
            'role' => Role::CgaAccountant,
            'status' => MembershipStatus::Active,
            'activated_at' => now(),
        ];
    }

    /**
     * Rôle donné, rattaché à une organisation du bon type : un nouveau CGA, ou le CNCGA et
     * l'organisation « plateforme », uniques, réutilisés s'ils existent. Un accès adhérent porte un
     * dossier de la même organisation.
     */
    public function role(Role $role): static
    {
        $type = $role->organizationType();

        return $this->state(fn (): array => [
            'role' => $role,
            'organization_id' => fn () => $type === OrganizationType::Cga
                ? Organization::factory()->state(['type' => $type])
                : (Organization::query()->where('type', $type)->value('id') ?? Organization::factory()->state(['type' => $type])),
            'member_id' => fn (array $attributes): ?string => $role->isMemberRole()
                ? Member::factory()->create(['organization_id' => $attributes['organization_id']])->id
                : null,
        ]);
    }

    public function invited(): static
    {
        return $this->state(fn (): array => ['status' => MembershipStatus::Invited, 'activated_at' => null]);
    }
}
