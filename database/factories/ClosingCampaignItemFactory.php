<?php

namespace Database\Factories;

use App\Domain\Closing\Enums\ClosingDeliverable;
use App\Domain\Closing\Models\ClosingCampaign;
use App\Domain\Closing\Models\ClosingCampaignItem;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Models\Member;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * Suivi de test d'un dossier validé dans une campagne de clôture de son centre, aucune étape faite.
 * Écrit hors espace actif, par un contournement nommé.
 *
 * @extends Factory<ClosingCampaignItem>
 */
class ClosingCampaignItemFactory extends Factory
{
    protected $model = ClosingCampaignItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campaign_id' => ClosingCampaign::factory(),
            'organization_id' => fn (array $attributes): string => (string) $this->campaign($attributes)->organization_id,
            'member_id' => fn (array $attributes): string => Member::factory()->active()->create(['organization_id' => $this->campaign($attributes)->organization_id])->id,
            'portfolio' => Portfolio::Cga,
            'deliverable' => ClosingDeliverable::Smt,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function campaign(array $attributes): ClosingCampaign
    {
        return Tenancy::bypass(static fn (): ClosingCampaign => ClosingCampaign::query()->findOrFail($attributes['campaign_id']), 'Fabrique de test : campagne du suivi.');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        return Tenancy::bypass(fn () => parent::create($attributes, $parent), 'Fabrique de test : suivi de clôture.');
    }
}
