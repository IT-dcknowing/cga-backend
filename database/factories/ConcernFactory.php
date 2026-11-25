<?php

namespace Database\Factories;

use App\Domain\Concerns\Enums\ConcernCategory;
use App\Domain\Concerns\Enums\ConcernLevel;
use App\Domain\Concerns\Enums\ConcernStatus;
use App\Domain\Concerns\Models\Concern;
use App\Domain\Members\Models\Member;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * Préoccupation de test : demande ouverte d'un adhérent à son CGA, sans message. Écrite hors espace
 * actif, par un contournement nommé.
 *
 * @extends Factory<Concern>
 */
class ConcernFactory extends Factory
{
    protected $model = Concern::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'level' => ConcernLevel::MemberToCga,
            'member_id' => fn (array $attributes): ?string => ConcernLevel::from($attributes['level'] instanceof ConcernLevel ? $attributes['level']->value : (string) $attributes['level']) === ConcernLevel::MemberToCga
                ? Member::factory()->active()->create(['organization_id' => $attributes['organization_id']])->id
                : null,
            'author_user_id' => User::factory(),
            'category' => ConcernCategory::Tax,
            'subject' => 'Question sur la patente',
            'status' => ConcernStatus::Open,
            'target_response_days' => 7,
            'last_activity_at' => now(),
            'awaiting_reply_since' => now(),
        ];
    }

    /** Demande d'un CGA au CNCGA. */
    public function toNetwork(): static
    {
        return $this->state(fn (): array => ['level' => ConcernLevel::CgaToCncga, 'member_id' => null, 'target_response_days' => 15]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        return Tenancy::bypass(fn () => parent::create($attributes, $parent), 'Fabrique de test : préoccupation.');
    }
}
