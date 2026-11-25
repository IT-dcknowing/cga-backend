<?php

namespace App\Domain\Notifications\Support;

use App\Support\Config\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Quota SMS mensuel d'un centre (02-TRD §10, sms_quotas) : inclus (sms.monthly_quota) plus packs
 * achetés ; un SMS au-delà est refusé (« Quota épuisé »). Les codes de connexion sont hors quota (ils
 * ne passent pas par ici).
 */
final class SmsQuotas
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Réserve un SMS du mois pour le centre ; faux si le quota est épuisé.
     */
    public function consume(string $organizationId, CarbonImmutable $now): bool
    {
        return DB::transaction(function () use ($organizationId, $now): bool {
            $month = $now->startOfMonth()->toDateString();
            DB::table('sms_quotas')->insertOrIgnore([
                'organization_id' => $organizationId,
                'month' => $month,
                'included' => (int) $this->settings->get('sms.monthly_quota', $organizationId),
                'extra' => 0,
                'used' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return DB::table('sms_quotas')
                ->where('organization_id', $organizationId)
                ->where('month', $month)
                ->whereRaw('used < included + extra')
                ->increment('used', 1, ['updated_at' => $now]) === 1;
        });
    }
}
