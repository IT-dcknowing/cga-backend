<?php

namespace Database\Factories;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Referentials\Enums\SectorStatus;
use App\Domain\Referentials\Models\Sector;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sector>
 */
class SectorFactory extends Factory
{
    protected $model = Sector::class;

    /**
     * Un secteur national validé par défaut.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->lexify('???').fake()->numerify('###'),
            'name' => 'Secteur '.fake()->unique()->word(),
            'status' => SectorStatus::Validated,
            'validated_at' => now(),
        ];
    }

    /**
     * Proposé par un CGA, en attente de la validation du CNCGA (RG-REF-01).
     */
    public function proposedBy(Organization $organization): static
    {
        return $this->state(fn (): array => [
            'status' => SectorStatus::Proposed,
            'proposed_by_organization_id' => $organization->id,
            'validated_at' => null,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => ['status' => SectorStatus::Rejected, 'validated_at' => null]);
    }
}
