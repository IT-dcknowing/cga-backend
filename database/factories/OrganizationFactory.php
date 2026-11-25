<?php

namespace Database\Factories;

use App\Domain\Organizations\Enums\AgreementStatus;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Enums\SubscriptionStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Referentials\Models\TaxOffice;
use App\Support\Files\StorageService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * Un CGA agréé, actif et abonné par défaut (un CGA non abonné n'a aucun accès, RG-ORG-06).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'CGA '.fake()->unique()->company();

        return [
            'type' => OrganizationType::Cga,
            'name' => $name,
            'sigle' => Str::upper(Str::substr(Str::slug($name, ''), 0, 8)),
            'numbering_abbreviation' => Str::upper(fake()->unique()->lexify('????')),
            'agreement_number' => fake()->unique()->numerify('AGR-####-###'),
            'agreement_date' => fake()->dateTimeBetween('-10 years', '-1 year'),
            'agreement_status' => AgreementStatus::Granted,
            'city' => fake()->randomElement(['Abidjan', 'Bouaké', 'Yamoussoukro', 'San-Pédro', 'Korhogo']),
            'phone_1' => '+22527'.fake()->numerify('########'),
            'email' => fake()->unique()->companyEmail(),
            'status' => OrganizationStatus::Active,
            'subscription_status' => SubscriptionStatus::Active,
            'subscription_started_at' => now()->subYear(),
        ];
    }

    public function cga(): static
    {
        return $this->state(fn (): array => ['type' => OrganizationType::Cga]);
    }

    public function cncga(): static
    {
        return $this->state(fn (): array => [
            'type' => OrganizationType::Cncga,
            'name' => 'Collectif National des Centres de Gestion Agréés',
            'sigle' => 'CNCGA',
            'numbering_abbreviation' => null,
            'agreement_number' => null,
            'agreement_status' => AgreementStatus::Pending,
        ]);
    }

    public function platform(): static
    {
        return $this->state(fn (): array => [
            'type' => OrganizationType::Platform,
            'name' => 'CGA Connect',
            'sigle' => null,
            'numbering_abbreviation' => null,
            'agreement_number' => null,
            'agreement_status' => AgreementStatus::Pending,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => [
            'status' => OrganizationStatus::Suspended,
            'suspended_at' => now(),
            'suspension_reason' => 'Suspension de test',
        ]);
    }

    /**
     * Fiche complète (RG-ORG-04) : identité, rattachements et images du logo, de la signature et du
     * cachet, rangées par StorageService ; les documents du centre se produisent.
     */
    public function profileComplete(): static
    {
        return $this->state(fn (): array => [
            'legal_form' => 'SARL',
            'share_capital' => 1_000_000,
            'ncc' => fake()->unique()->numerify('#######').'C',
            'rccm' => 'CI-ABJ-2020-B-'.fake()->unique()->numerify('#####'),
            'tax_office_id' => TaxOffice::factory(),
            'regional_directorate_id' => fn (array $attributes): ?string => TaxOffice::query()->whereKey($attributes['tax_office_id'])->value('regional_directorate_id'),
            'address' => 'Plateau, rue du Commerce',
            'manager_name' => 'Koffi Kouadio',
            'expert_name' => 'Cabinet Expert',
            'signatory_name' => 'Koffi Kouadio',
            'signatory_title' => 'Le Gérant',
        ])->afterCreating(function (Organization $organization): void {
            $storage = app(StorageService::class);
            foreach (['logo_file_id', 'signature_file_id', 'stamp_file_id'] as $column) {
                $organization->setAttribute($column, $storage->store(self::png(), 'image.png', 'branding', $organization->id)->id);
            }
            $organization->profile_completed_at = now()->toImmutable();
            $organization->save();
        });
    }

    /**
     * Petite image PNG unie.
     */
    private static function png(): string
    {
        $image = imagecreatetruecolor(40, 20);
        if ($image === false) {
            throw new RuntimeException('Image de test impossible à créer.');
        }
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 106, 78));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
