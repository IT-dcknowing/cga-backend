<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Enums\OtpPurpose;
use App\Domain\Auth\Support\Identifier;
use App\Domain\Users\Enums\UserStatus;
use App\Domain\Users\Models\User;
use App\Support\Http\Exceptions\BusinessRuleException;
use App\Support\Phones\PhoneNormalizer;

/**
 * Mot de passe oublié, étape 2 (F-AUTH-03) : le code reçu remplace le mot de passe. Code faux :
 * 409 wrong_code (essais restants) ; expiré, épuisé ou compte inconnu : 409 code_expired.
 */
class ResetPassword
{
    public function __construct(
        private readonly PhoneNormalizer $phones,
        private readonly ConsumeOneTimeCode $consume,
    ) {}

    /**
     * @throws BusinessRuleException wrong_code ou code_expired
     */
    public function execute(string $identifier, #[\SensitiveParameter] string $code, #[\SensitiveParameter] string $password): void
    {
        $parsed = Identifier::parse($identifier, $this->phones);
        $user = $parsed->user();

        if ($user === null || $user->status !== UserStatus::Active) {
            throw new BusinessRuleException('code_expired');
        }

        $this->consume->execute($user, OtpPurpose::PasswordReset, $parsed->value, $code, static function () use ($user, $password): User {
            $user->password = $password;
            $user->save();

            return $user;
        });
    }
}
