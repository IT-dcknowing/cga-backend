<?php

namespace Database\Factories;

use App\Domain\Auth\Enums\InvitationChannel;
use App\Domain\Auth\Models\Invitation;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    protected $model = Invitation::class;

    /**
     * Invitation par SMS d'un rattachement en attente, envoyée à l'instant, valable 7 jours.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'membership_id' => fn (): string => Membership::factory()->invited()->create([
                'user_id' => User::factory()->invited(),
            ])->id,
            'token_hash' => Invitation::fingerprint(Str::random(43)),
            'channel' => InvitationChannel::Sms,
            'sent_at' => now(),
            'expires_at' => now()->addDays(7),
        ];
    }

    /**
     * Jeton connu du test (le lien /activer/{jeton}).
     */
    public function token(string $token): static
    {
        return $this->state(fn (): array => ['token_hash' => Invitation::fingerprint($token)]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['sent_at' => now()->subDays(8), 'expires_at' => now()->subDay()]);
    }

    public function accepted(): static
    {
        return $this->state(fn (): array => ['accepted_at' => now()->subHour()]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => ['revoked_at' => now()->subHour()]);
    }
}
