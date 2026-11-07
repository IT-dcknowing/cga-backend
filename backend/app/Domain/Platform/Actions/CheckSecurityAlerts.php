<?php

namespace App\Domain\Platform\Actions;

use App\Domain\Notifications\Support\Recipients;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Enums\Role;
use App\Support\Config\SettingsService;
use App\Support\Logging\AlertCounter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;

/**
 * Alertes de sécurité (02-TRD §6.7, A09) : pics d'échecs de connexion, de refus 403, d'erreurs 500 et de
 * contournements du cloisonnement pendant des requêtes. Les compteurs du jour (AlertCounter) sont comparés aux seuils
 * du paramètre de la plateforme alerts.daily_thresholds ; une alerte dépassée est signalée une seule fois par jour
 * aux administrateurs de la plateforme (cloche et e-mail) et inscrite au journal structuré (Sentry si configuré).
 * Aucune donnée de requête n'est copiée : le nom de l'alerte, le compte et le seuil seulement.
 */
final class CheckSecurityAlerts
{
    public const SENT_PREFIX = 'alerts-sent:';

    public function __construct(
        private readonly AlertCounter $counter,
        private readonly SettingsService $settings,
        private readonly Recipients $recipients,
        private readonly Cache $cache,
    ) {}

    /**
     * @return array<string, int> alertes signalées : nom => compte du jour
     */
    public function execute(CarbonImmutable $now): array
    {
        $raised = [];
        foreach ((array) $this->settings->get('alerts.daily_thresholds') as $alert => $threshold) {
            $alert = (string) $alert;
            $count = $this->counter->count($alert, $now);
            if ($count < (int) $threshold) {
                continue;
            }
            // Une seule alerte par jour et par compteur, même si la tâche repasse.
            if (! $this->cache->add(self::SENT_PREFIX.$alert.':'.$now->toDateString(), true, $now->endOfDay())) {
                continue;
            }

            $raised[$alert] = $count;
            Log::warning('Alerte de sécurité : seuil du jour dépassé.', ['alert' => $alert, 'count' => $count, 'threshold' => (int) $threshold]);
            $this->notify($alert, $count, (int) $threshold, $now);
        }

        return $raised;
    }

    private function notify(string $alert, int $count, int $threshold, CarbonImmutable $now): void
    {
        $platform = Organization::query()->where('type', OrganizationType::Platform)->value('id');
        if (! is_string($platform)) {
            return;
        }

        $this->recipients->notify($this->recipients->roles($platform, [Role::PlatformAdmin]), 'security_alert', [
            'alert' => (string) __("alerts.names.{$alert}"),
            'count' => $count,
            'threshold' => $threshold,
            'date' => $now->format((string) config('cga.exports.date_format')),
        ]);
    }
}
