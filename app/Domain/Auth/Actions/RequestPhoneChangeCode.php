<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Data\IssuedCode;
use App\Domain\Auth\Enums\OtpPurpose;
use App\Domain\Users\Models\User;
use App\Support\Http\Exceptions\TooManyAttemptsException;
use App\Support\Phones\PhoneNormalizer;
use App\Support\RateLimiting\AttemptLimiter;

/**
 * Changement de téléphone, étape 1 (F-AUTH-05) : code par SMS au nouveau numéro, dans la limite de
 * rate_limit.otp (3 demandes par heure et par numéro).
 */
class RequestPhoneChangeCode
{
    public const LIMIT = 'rate_limit.otp';

    public function __construct(
        private readonly AttemptLimiter $limiter,
        private readonly PhoneNormalizer $phones,
        private readonly IssueOneTimeCode $issue,
    ) {}

    /**
     * @param  string  $phone  nouveau numéro, déjà validé
     *
     * @throws TooManyAttemptsException
     */
    public function execute(User $user, string $phone): IssuedCode
    {
        $phone = $this->phones->e164OrFail($phone);
        $this->limiter->consume(self::LIMIT, $phone);

        return $this->issue->execute($user, OtpPurpose::PhoneChange, $phone, 'sms');
    }
}
