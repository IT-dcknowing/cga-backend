<?php

namespace App\Domain\Statistics\Support;

use App\Domain\Statistics\Models\StatOrgDaily;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Position du CGA dans le réseau (F-STA-02, /cga/statistiques) : ses indicateurs de la période comparés aux
 * moyennes anonymisées du réseau, données seulement à partir de stats.min_cgas_for_average CGA contributeurs
 * (RG-STA-04), sinon « Données insuffisantes ». Ses chiffres sont lus dans son espace ; la moyenne ne révèle aucun
 * autre CGA.
 */
final class CgaPosition
{
    public const METRICS = ['active_members', 'new_members', 'radiations', 'fees_paid', 'tax_paid_total', 'declared_revenue', 'concerns_avg_response_hours'];

    public function __construct(
        private readonly OrgStatsSummary $summary,
        private readonly NetworkAverages $averages,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(StatPeriod $period, CarbonImmutable $today): array
    {
        $until = $period->until($today);
        $own = $this->summary->byOrganization($period->from, $until)[$this->context->organizationId()] ?? [];
        $network = $this->averages->compute($period->from, $until, self::METRICS);

        $indicators = [];
        foreach (self::METRICS as $metric) {
            $indicators[$metric] = ['own' => $own[$metric] ?? null, 'network_average' => $network['values'][$metric]];
        }
        $computed = StatOrgDaily::query()->max('computed_at');

        return [
            'period' => $period->toArray(),
            'computed_at' => $computed === null ? null : CarbonImmutable::parse((string) $computed)->toIso8601String(),
            'network' => [
                'contributors' => $network['contributors'],
                'minimum' => $network['minimum'],
                'sufficient' => $network['sufficient'],
                'label' => $network['sufficient'] ? null : (string) __('statistics.insufficient'),
            ],
            'indicators' => $indicators,
        ];
    }
}
