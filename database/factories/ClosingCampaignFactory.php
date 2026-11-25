<?php

namespace Database\Factories;

use App\Domain\Closing\Enums\ClosingCampaignStatus;
use App\Domain\Closing\Models\ClosingCampaign;
use App\Domain\Organizations\Models\Organization;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * Campagne de clôture de test : exercice 2026, calendrier par défaut en 2027, ouverte. Écrite hors
 * espace actif, par un contournement nommé.
 *
 * @extends Factory<ClosingCampaign>
 */
class ClosingCampaignFactory extends Factory
{
    protected $model = ClosingCampaign::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'fiscal_year' => 2026,
            'steps' => [
                ['code' => 'pieces', 'due' => '2027-01-30'],
                ['code' => 'regularisation', 'due' => '2027-02-28'],
                ['code' => 'finalisation', 'due' => '2027-03-31'],
                ['code' => 'filing', 'due' => '2027-05-30'],
            ],
            'status' => ClosingCampaignStatus::Open,
            'opened_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        return Tenancy::bypass(fn () => parent::create($attributes, $parent), 'Fabrique de test : campagne de clôture.');
    }
}
