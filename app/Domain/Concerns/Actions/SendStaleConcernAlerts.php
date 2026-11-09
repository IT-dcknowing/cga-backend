<?php

namespace App\Domain\Concerns\Actions;

use App\Domain\Concerns\Enums\ConcernLevel;
use App\Domain\Concerns\Enums\ConcernStatus;
use App\Domain\Concerns\Models\Concern;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Notifications\Support\Recipients;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Permissions\PermissionResolver;
use App\Support\Config\SettingsService;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * `reminders:concerns` (02-TRD §11, RG-PRE-02) : une demande d'adhérent sans réponse depuis
 * concerns.stale_days jours déclenche une alerte interne au CGA — une notification par agent qui répond
 * aux adhérents, avec le nombre de demandes qu'il voit. Une seule alerte par attente : une nouvelle attente
 * (message de l'adhérent après une réponse, réouverture) en appelle une nouvelle. Aucune remontée au CNCGA.
 */
class SendStaleConcernAlerts
{
    public function __construct(
        private readonly Recipients $recipients,
        private readonly PermissionResolver $permissions,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @return int demandes signalées
     */
    public function execute(CarbonImmutable $now): int
    {
        return Tenancy::bypass(function () use ($now): int {
            $alerted = 0;
            Concern::query()
                ->with('member')
                ->where('level', ConcernLevel::MemberToCga)
                ->whereIn('status', [ConcernStatus::Open, ConcernStatus::InReview])
                ->whereNotNull('awaiting_reply_since')
                ->where(static fn (Builder $query) => $query->whereNull('stale_alert_sent_at')->orWhereColumn('stale_alert_sent_at', '<=', 'awaiting_reply_since'))
                ->get()
                ->groupBy('organization_id')
                ->each(function (Collection $concerns, string $organizationId) use ($now, &$alerted): void {
                    $days = (int) $this->settings->get('concerns.stale_days', $organizationId);
                    $stale = $concerns->filter(static fn (Concern $concern): bool => $concern->awaiting_reply_since !== null && $concern->awaiting_reply_since->lessThanOrEqualTo($now->subDays($days)))->values();
                    if ($stale->isEmpty()) {
                        return;
                    }

                    $this->alert($organizationId, $stale, $days);
                    $stale->each(static function (Concern $concern) use ($now): void {
                        $concern->stale_alert_sent_at = $now;
                        $concern->save();
                    });
                    $alerted += $stale->count();
                });

            return $alerted;
        }, 'Tâche reminders:concerns : demandes d\'adhérents sans réponse de tous les CGA (RG-PRE-02).');
    }

    /**
     * @param  Collection<int, Concern>  $stale
     */
    private function alert(string $organizationId, Collection $stale, int $days): void
    {
        $cabinet = $stale->filter(static fn (Concern $concern): bool => $concern->member?->portfolio === Portfolio::Cabinet)->count();

        $this->recipients->agents($organizationId, 'concerns.reply')->each(function (Membership $agent) use ($stale, $cabinet, $days): void {
            $count = $this->permissions->allows($agent, 'members.cabinet.view') ? $stale->count() : $stale->count() - $cabinet;
            if ($count > 0) {
                $this->recipients->notify([$agent], 'concern_stale', ['count' => $count, 'days' => $days], '/cga/preoccupations');
            }
        });
    }
}
