<?php

namespace Database\Factories;

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Members\Models\Member;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Document produit de test : contrat d'adhésion valide d'un dossier validé. Écrit hors espace actif,
 * par un contournement nommé.
 *
 * @extends Factory<GeneratedDocument>
 */
class GeneratedDocumentFactory extends Factory
{
    protected $model = GeneratedDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'member_id' => Member::factory()->active(),
            'organization_id' => fn (array $attributes): string => (string) Tenancy::bypass(static fn () => Member::query()->whereKey($attributes['member_id'])->value('organization_id'), 'Fabrique de test : centre du dossier.'),
            'type' => DocumentType::MembershipContract,
            'title' => 'Contrat d\'adhésion',
            'version' => 1,
            'verification_token' => Str::random(43),
            'status' => DocumentStatus::Valid,
            'issued_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        return Tenancy::bypass(fn () => parent::create($attributes, $parent), 'Fabrique de test : document produit.');
    }
}
