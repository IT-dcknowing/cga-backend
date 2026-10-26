<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Data\IssuedCode;
use App\Domain\Auth\Enums\OtpPurpose;
use App\Domain\Auth\Support\Identifier;
use App\Domain\Users\Enums\UserStatus;
use App\Support\Http\Exceptions\TooManyAttemptsException;
use App\Support\Phones\PhoneNormalizer;
use App\Support\RateLimiting\AttemptLimiter;

/**
 * Mot de passe oublié, étape 1 (F-AUTH-03, RG-ACC-04) : code par SMS au téléphone saisi, ou par
 * e-mail si l'identifiant saisi est l'e-mail du compte. rate_limit.otp (3 demandes par heure et par
 * identifiant) s'applique que le compte existe ou non ; la réponse est toujours la même (A07).
 */
class RequestPasswordResetCode
{
    public const LIMIT = 'rate_limit.otp';

    public function __construct(
        private readonly AttemptLimiter $limiter,
        private readonly PhoneNormalizer $phones,
        private readonly IssueOneTimeCode $issue,
    ) {}

    /**
     * @return IssuedCode|null le code envoyé, null si aucun compte actif ne correspond
     *
     * @throws TooManyAttemptsException
     */
    public function execute(string $identifier): ?IssuedCode
    {
        $parsed = Identifier::parse($identifier, $this->phones);
        $this->limiter->consume(self::LIMIT, $parsed->value);

        $user = $parsed->user();
        if ($user === null || $user->status !== UserStatus::Active) {
            return null;
        }

        return $this->issue->execute($user, OtpPurpose::PasswordReset, $parsed->value, $parsed->channel());
    }
}
