<?php

namespace Database\Factories;

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
     * Rôle donné, rattaché à une organisation du bon type (CNCGA, CGA ou plateforme).
     */
    public function role(Role $role): static
    {
        return $this->state(fn (): array => [
            'role' => $role,
            'organization_id' => fn () => Organization::factory()->state(['type' => $role->organizationType()]),
        ]);
    }

    public function invited(): static
    {
        return $this->state(fn (): array => ['status' => MembershipStatus::Invited, 'activated_at' => null]);
    }
}
