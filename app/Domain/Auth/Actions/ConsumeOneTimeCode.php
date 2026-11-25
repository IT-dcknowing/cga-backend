<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Enums\OtpPurpose;
use App\Domain\Auth\Models\OtpCode;
use App\Domain\Users\Models\User;
use App\Support\Config\SettingsService;
use App\Support\Http\Exceptions\BusinessRuleException;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Vérifie un code à usage unique (02-TRD §6.1 : otp.max_attempts essais, 3 par défaut) et, s'il est
 * bon, exécute dans la même transaction l'opération qu'il autorise. Un code faux compte un essai,
 * enregistré bien que la demande soit refusée : 409 wrong_code avec details.attempts_left. Un code
 * expiré, épuisé ou inconnu : 409 code_expired (l'utilisateur redemande un code).
 */
class ConsumeOneTimeCode
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $then  opération autorisée par le code
     * @return T
     *
     * @throws BusinessRuleException wrong_code ou code_expired
     */
    public function execute(User $user, OtpPurpose $purpose, string $target, #[\SensitiveParameter] string $code, Closure $then): mixed
    {
        $maxAttempts = (int) $this->settings->get('otp.max_attempts');

        // L'essai compté doit survivre au refus : la transaction est validée, l'erreur levée ensuite.
        [$outcome, $value] = DB::transaction(function () use ($user, $purpose, $target, $code, $then, $maxAttempts): array {
            $otp = OtpCode::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->where('target', $target)
                ->whereNull('consumed_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($otp === null || $otp->expires_at->isPast() || $otp->attempts >= $maxAttempts) {
                return ['expired', null];
            }

            if (! hash_equals($otp->code_hash, OtpCode::fingerprint($code))) {
                $otp->attempts++;
                $otp->save();

                return ['wrong', $maxAttempts - $otp->attempts];
            }

            $otp->consumed_at = now();
            $otp->save();

            return ['accepted', $then()];
        });

        return match ($outcome) {
            'expired' => throw new BusinessRuleException('code_expired'),
            'wrong' => throw new BusinessRuleException('wrong_code', __('errors.wrong_code', ['attempts_left' => $value]), ['attempts_left' => $value]),
            default => $value,
        };
    }
}
