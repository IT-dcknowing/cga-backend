<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Support\Identifier;
use App\Domain\Users\Enums\UserStatus;
use App\Domain\Users\Models\User;
use App\Support\Http\Exceptions\BusinessRuleException;
use App\Support\Http\Exceptions\TooManyAttemptsException;
use App\Support\Logging\AlertCounter;
use App\Support\Phones\PhoneNormalizer;
use App\Support\RateLimiting\AttemptLimiter;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Vérifie téléphone (ou e-mail) et mot de passe (F-AUTH-01) :
 * - échec : « Identifiants incorrects », sans dire si le compte existe, en un temps comparable
 *   (un mot de passe est vérifié même sans compte) ;
 * - rate_limit.login (5 échecs en 15 min par identifiant et adresse IP) : 429 too_many_attempts ;
 * - compte sans rattachement actif ou désactivé : 403 inactive_account (« Votre accès n'est pas
 *   actif, contactez votre CGA »), seulement après un mot de passe juste.
 */
class AttemptLogin
{
    public const LIMIT = 'rate_limit.login';

    public const FAILED_ALERT = 'login_failed';

    private static ?string $decoyHash = null;

    public function __construct(
        private readonly AttemptLimiter $limiter,
        private readonly PhoneNormalizer $phones,
        private readonly Hasher $hasher,
        private readonly AlertCounter $alerts,
    ) {}

    /**
     * @throws ValidationException identifiants incorrects
     * @throws TooManyAttemptsException
     * @throws BusinessRuleException inactive_account
     */
    public function execute(string $identifier, #[\SensitiveParameter] string $password, string $ip): User
    {
        $parsed = Identifier::parse($identifier, $this->phones);
        $throttleKey = $parsed->value.'|'.$ip;
        $this->limiter->ensureAvailable(self::LIMIT, $throttleKey);

        $user = $parsed->user();
        $matches = $this->passwordMatches($user?->password, $password);
        if ($user === null || ! $matches) {
            $this->alerts->increment(self::FAILED_ALERT);
            $this->limiter->fail(self::LIMIT, $throttleKey);

            throw ValidationException::withMessages(['identifier' => __('auth.failed')]);
        }

        $this->limiter->clear(self::LIMIT, $throttleKey);

        if ($user->status !== UserStatus::Active || ! $user->memberships()->usable()->exists()) {
            throw new BusinessRuleException('inactive_account', status: 403);
        }

        return DB::transaction(function () use ($user, $password): User {
            if ($this->hasher->needsRehash((string) $user->password)) {
                $user->password = $password;
            }
            $user->last_login_at = now();
            $user->save();

            return $user;
        });
    }

    private function passwordMatches(?string $hash, #[\SensitiveParameter] string $password): bool
    {
        if ($hash === null) {
            // Même coût qu'une vraie vérification : la durée ne trahit pas l'absence de compte.
            $this->hasher->check($password, self::$decoyHash ??= $this->hasher->make(Str::random(40)));

            return false;
        }

        return $this->hasher->check($password, $hash);
    }
}
