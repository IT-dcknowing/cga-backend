<?php

namespace App\Domain\Statistics\Support;

use App\Support\Config\SettingsService;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;

/**
 * Moyennes anonymisées du réseau (F-STA-02, « position du CGA ») : moyenne par CGA abonné des indicateurs d'une
 * période (OrgStatsSummary). Une moyenne n'est donnée que si au moins stats.min_cgas_for_average CGA y
 * contribuent (RG-STA-04, 5 par défaut), sinon null (« données insuffisantes ») ; aucun chiffre d'un autre CGA
 * n'est jamais renvoyé, seulement la moyenne et le nombre de contributeurs.
 */
final class NetworkAverages
{
    private const ROUNDING = 2;

    public function __construct(
        private readonly OrgStatsSummary $summary,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @param  list<string>  $metrics
     * @return array{contributors: int, minimum: int, sufficient: bool, values: array<string, float|null>}
     */
    public function compute(CarbonImmutable $from, CarbonImmutable $to, array $metrics): array
    {
        $byOrganization = Tenancy::bypass(
            fn (): array => $this->summary->byOrganization($from, $to),
            'Statistiques : moyennes anonymisées du réseau (RG-STA-04).',
        );
        $minimum = (int) $this->settings->get('stats.min_cgas_for_average');
        $contributors = count($byOrganization);
        $sufficient = $contributors >= $minimum;

        $values = [];
        foreach ($metrics as $metric) {
            $known = array_values(array_filter(array_column($byOrganization, $metric), static fn (mixed $value): bool => $value !== null));
            $values[$metric] = $sufficient && count($known) >= $minimum ? round(array_sum($known) / count($known), self::ROUNDING) : null;
        }

        return ['contributors' => $contributors, 'minimum' => $minimum, 'sufficient' => $sufficient, 'values' => $values];
    }
}
