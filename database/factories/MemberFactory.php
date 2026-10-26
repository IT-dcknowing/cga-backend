<?php

namespace Database\Factories;

use App\Domain\Members\Enums\EntityType;
use App\Domain\Members\Enums\FeesPeriodicity;
use App\Domain\Members\Enums\LegalForm;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Members\Models\Member;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Referentials\Models\Sector;
use App\Domain\Referentials\Models\TaxOffice;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Dossier d'adhérent de test : un brouillon du portefeuille CGA par défaut, dans un nouveau CGA. Le
 * dossier est cloisonné : la fabrique l'écrit hors espace actif, par un contournement nommé.
 *
 * @extends Factory<Member>
 */
class MemberFactory extends Factory
{
    protected $model = Member::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'portfolio' => Portfolio::Cga,
            'status' => MemberStatus::Draft,
            'entity_type' => EntityType::Company,
            'company_name' => Str::upper(fake()->unique()->company()),
            'legal_form' => LegalForm::Sarl,
            'ncc' => fake()->unique()->numerify('#######').fake()->randomElement(['A', 'B', 'F', 'K', 'M', 'R']),
            'regime' => TaxRegime::Tee,
            'regime_since' => '2026-01-01',
            'main_activity' => 'Commerce général',
            'phone' => '+22507'.fake()->numerify('########'),
            'entry_fee_amount' => 25_000,
            'fees_amount' => 15_000,
            'fees_periodicity' => FeesPeriodicity::Monthly,
        ];
    }

    /**
     * Dossier validé du portefeuille CGA : contrat signé, numéro, fiche complète.
     */
    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => MemberStatus::Active,
            'adhesion_date' => '2026-03-01',
            'cga_contract_signed_on' => '2026-03-01',
            'validated_at' => now(),
            'membership_number' => '2026-TST-'.fake()->unique()->numerify('####'),
            'tax_office_id' => TaxOffice::factory(),
            'sector_id' => Sector::factory(),
            'address' => 'Abidjan, Cocody',
            'signatory_name' => 'Koffi Kouassi',
            'signatory_title' => 'Gérant',
        ]);
    }

    /**
     * Dossier du portefeuille Cabinet (RG-PTF-01).
     */
    public function cabinet(): static
    {
        return $this->state(fn (): array => ['portfolio' => Portfolio::Cabinet]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        return Tenancy::bypass(fn () => parent::create($attributes, $parent), 'Fabrique de test : dossier d\'adhérent.');
    }
}
