<?php

namespace Database\Factories;

use App\Domain\Users\Enums\UserStatus;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * Mot de passe commun des comptes de test, haché une seule fois.
     */
    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone' => '+22507'.fake()->unique()->numerify('########'),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'status' => UserStatus::Active,
        ];
    }

    /**
     * Compte invité qui n'a pas encore choisi son mot de passe.
     */
    public function invited(): static
    {
        return $this->state(fn (): array => ['status' => UserStatus::Invited, 'password' => null]);
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['status' => UserStatus::Disabled, 'disabled_at' => now()]);
    }
}
