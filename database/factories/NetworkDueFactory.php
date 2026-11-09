<?php

namespace Database\Factories;

use App\Domain\Dues\Enums\DuesStatus;
use App\Domain\Dues\Models\NetworkDue;
use App\Domain\Organizations\Models\Organization;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * Cotisation de test : 2026, 750 000 F dus au 30 juin, rien de réglé. Écrite hors espace actif, par un
 * contournement nommé.
 *
 * @extends Factory<NetworkDue>
 */
class NetworkDueFactory extends Factory
{
    protected $model = NetworkDue::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'year' => 2026,
            'base_amount' => 750_000,
            'prorata_ratio' => 1,
            'amount_due' => 750_000,
            'amount_paid' => 0,
            'deadline' => '2026-06-30',
            'status' => DuesStatus::Due,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        return Tenancy::bypass(fn () => parent::create($attributes, $parent), 'Fabrique de test : cotisation.');
    }
}
