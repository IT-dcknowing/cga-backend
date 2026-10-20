<?php

namespace Database\Factories;

use App\Domain\Organizations\Enums\AgreementStatus;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * Un CGA agréé et actif par défaut.
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
}
