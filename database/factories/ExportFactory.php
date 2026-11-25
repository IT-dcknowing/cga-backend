<?php

namespace Database\Factories;

use App\Domain\Exports\Enums\ExportStatus;
use App\Domain\Exports\Enums\ExportType;
use App\Domain\Exports\Models\Export;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * Export de test : adhérents d'un CGA demandés par son gérant, en file d'attente. Écrit hors espace actif, par un
 * contournement nommé.
 *
 * @extends Factory<Export>
 */
class ExportFactory extends Factory
{
    protected $model = Export::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'membership_id' => Membership::factory()->role(Role::CgaManager),
            'organization_id' => fn (array $attributes): string => (string) Membership::query()->whereKey($attributes['membership_id'])->value('organization_id'),
            'user_id' => fn (array $attributes): string => (string) Membership::query()->whereKey($attributes['membership_id'])->value('user_id'),
            'type' => ExportType::Members,
            'filters' => [],
            'status' => ExportStatus::Queued,
            'requested_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        return Tenancy::bypass(fn () => parent::create($attributes, $parent), 'Fabrique de test : export.');
    }
}
