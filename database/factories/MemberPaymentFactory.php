<?php

namespace Database\Factories;

use App\Domain\Members\Models\Member;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentSource;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\MemberPayment;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * Paiement de test : saisi par le CGA, confirmé. Écrit hors espace actif, par un contournement nommé.
 *
 * @extends Factory<MemberPayment>
 */
class MemberPaymentFactory extends Factory
{
    protected $model = MemberPayment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'member_id' => Member::factory()->active(),
            'organization_id' => fn (array $attributes): string => (string) Tenancy::bypass(static fn () => Member::query()->whereKey($attributes['member_id'])->value('organization_id'), 'Fabrique de test : centre du dossier.'),
            'amount' => 25_000,
            'paid_on' => today(),
            'method' => PaymentMethod::Cash,
            'source' => PaymentSource::CgaEntry,
            'status' => PaymentStatus::Confirmed,
            'confirmed_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        return Tenancy::bypass(fn () => parent::create($attributes, $parent), 'Fabrique de test : paiement.');
    }
}
