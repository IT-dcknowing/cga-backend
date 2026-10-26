<?php

namespace App\Domain\Users\Data;

/**
 * Identité saisie à l'invitation d'un agent (F-ORG-09) : nom, prénom, téléphone, e-mail. Si le
 * téléphone est celui d'un compte existant, c'est ce compte qui reçoit l'accès (RG-ACC-01) : son
 * identité ne change pas.
 */
final readonly class AgentIdentity
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $phone,
        public ?string $email,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        $email = $validated['email'] ?? null;

        return new self((string) $validated['first_name'], (string) $validated['last_name'], (string) $validated['phone'], is_string($email) ? $email : null);
    }
}
