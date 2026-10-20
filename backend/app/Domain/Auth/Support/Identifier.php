<?php

namespace App\Domain\Auth\Support;

use App\Domain\Users\Models\User;
use App\Support\Phones\PhoneNormalizer;

/**
 * Identifiant saisi à la connexion ou pour un mot de passe oublié (RG-ACC-04) : un e-mail, ou un
 * téléphone ramené au format E.164. Une saisie qui n'est ni l'un ni l'autre ne désigne aucun compte.
 */
final readonly class Identifier
{
    private function __construct(
        public string $value,
        public bool $isEmail,
    ) {}

    public static function parse(string $input, PhoneNormalizer $phones): self
    {
        $input = trim($input);

        if (str_contains($input, '@')) {
            return new self(mb_strtolower($input), true);
        }

        return new self($phones->e164($input) ?? mb_strtolower($input), false);
    }

    public function user(): ?User
    {
        return User::query()->where($this->isEmail ? 'email' : 'phone', $this->value)->first();
    }

    /**
     * Canal de notification qui atteint cet identifiant : e-mail ou SMS.
     */
    public function channel(): string
    {
        return $this->isEmail ? 'mail' : 'sms';
    }
}
