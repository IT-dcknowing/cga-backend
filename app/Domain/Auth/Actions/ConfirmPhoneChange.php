<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Enums\OtpPurpose;
use App\Domain\Users\Models\User;
use App\Support\Http\Exceptions\BusinessRuleException;
use App\Support\Phones\PhoneNormalizer;

/**
 * Changement de téléphone, étape 2 (F-AUTH-05) : le code reçu au nouveau numéro le confirme ; le
 * numéro devient l'identifiant du compte (RG-ACC-01). Un numéro pris entre-temps par un autre compte
 * est refusé (409 phone_taken).
 */
class ConfirmPhoneChange
{
    public function __construct(
        private readonly PhoneNormalizer $phones,
        private readonly ConsumeOneTimeCode $consume,
    ) {}

    /**
     * @param  string  $phone  nouveau numéro, déjà validé
     *
     * @throws BusinessRuleException wrong_code, code_expired ou phone_taken
     */
    public function execute(User $user, string $phone, #[\SensitiveParameter] string $code): User
    {
        $phone = $this->phones->e164OrFail($phone);

        return $this->consume->execute($user, OtpPurpose::PhoneChange, $phone, $code, static function () use ($user, $phone): User {
            if (User::query()->where('phone', $phone)->whereKeyNot($user->id)->lockForUpdate()->exists()) {
                throw new BusinessRuleException('phone_taken');
            }

            $user->phone = $phone;
            $user->phone_verified_at = now();
            $user->save();

            return $user;
        });
    }
}
