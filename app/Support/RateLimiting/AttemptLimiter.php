<?php

namespace App\Support\RateLimiting;

use App\Support\Config\SettingsService;
use App\Support\Http\Exceptions\TooManyAttemptsException;
use Illuminate\Cache\RateLimiter;

/**
 * Limitation des essais (02-TRD §6.1, A07) d'après les paramètres rate_limit.* (nombre d'essais et
 * fenêtre en minutes, réglables par l'administrateur). Les compteurs vivent dans le cache
 * (CACHE_STORE : base ou Redis) ; la clé ne garde que l'empreinte de l'identifiant visé.
 */
class AttemptLimiter
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Refuse si la limite est déjà atteinte (ex. connexion bloquée après 5 échecs).
     *
     * @param  string  $setting  paramètre de la limite (rate_limit.login…)
     *
     * @throws TooManyAttemptsException
     */
    public function ensureAvailable(string $setting, string $subject): void
    {
        $key = $this->key($setting, $subject);

        if ($this->limiter->tooManyAttempts($key, $this->limit($setting)['max_attempts'])) {
            throw new TooManyAttemptsException($this->limiter->availableIn($key));
        }
    }

    /**
     * Compte un essai ; refuse celui qui dépasse la limite (ex. 4e demande de code dans l'heure).
     *
     * @throws TooManyAttemptsException
     */
    public function consume(string $setting, string $subject): void
    {
        $this->ensureAvailable($setting, $subject);
        $this->limiter->hit($this->key($setting, $subject), $this->limit($setting)['decay_minutes'] * 60);
    }

    /**
     * Compte un échec ; celui qui atteint la limite bloque aussitôt (5e échec de connexion).
     *
     * @throws TooManyAttemptsException
     */
    public function fail(string $setting, string $subject): void
    {
        $key = $this->key($setting, $subject);
        $limit = $this->limit($setting);

        if ($this->limiter->hit($key, $limit['decay_minutes'] * 60) >= $limit['max_attempts']) {
            throw new TooManyAttemptsException($this->limiter->availableIn($key));
        }
    }

    public function clear(string $setting, string $subject): void
    {
        $this->limiter->clear($this->key($setting, $subject));
    }

    private function key(string $setting, string $subject): string
    {
        return $setting.':'.hash('sha256', $subject);
    }

    /**
     * @return array{max_attempts: int, decay_minutes: int}
     */
    private function limit(string $setting): array
    {
        /** @var array{max_attempts: int|string, decay_minutes: int|string} $limit */
        $limit = $this->settings->get($setting);

        return ['max_attempts' => (int) $limit['max_attempts'], 'decay_minutes' => (int) $limit['decay_minutes']];
    }
}
