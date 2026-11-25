<?php

namespace App\Domain\Reports\Support;

use App\Domain\Documents\Support\DocumentFormat;
use Carbon\CarbonImmutable;

/**
 * Synthèse chiffrée pré-rédigée du rapport modernisé (RG-RPT-09, 02-TRD §8.4) : portefeuille à la fin du
 * trimestre et variation sur le trimestre précédent, mouvements, recouvrement de la TEE, paiements RME,
 * campagne de clôture. Phrases modèles dans lang/fr/report.php, jamais en dur ; le CGA reprend ou
 * modifie le texte dans son analyse qualitative.
 */
final class ReportSynthesis
{
    /**
     * @param  array<string, mixed>  $states  états du portefeuille (PortfolioStates)
     * @param  array<string, mixed>  $content  contenu saisi
     * @param  list<array{label: string, tee: int, rme: int, total: int}>  $trend  adhérents actifs par trimestre, le dernier étant le trimestre du rapport
     */
    public function propose(array $states, array $content, array $trend, string $periodEnd): string
    {
        $totals = $states['sheets']['summary']['totals'];
        $previous = count($trend) > 1 ? $trend[count($trend) - 2]['total'] : null;
        $change = $previous === null ? null : $totals['active_total'] - $previous;
        $teeDue = (int) array_sum(array_map(static fn (array $group): int => (int) ($group['totals']['quarter_net'] ?? 0), $states['sheets']['tee_follow_up']));
        $teePaid = (int) array_sum(array_map(static fn (array $group): int => (int) ($group['totals']['quarter_paid'] ?? 0), $states['sheets']['tee_follow_up']));
        $rmePaid = (int) array_sum(array_map(static fn (array $group): int => (int) ($group['totals']['total'] ?? 0), $states['sheets']['rme_follow_up']));

        $sentences = [
            __('report.synthesis.portfolio', [
                'date' => DocumentFormat::date(CarbonImmutable::parse($periodEnd)),
                'total' => $totals['active_total'],
                'tee' => $totals['active_tee'],
                'rme' => $totals['active_rme'],
                'variation' => match (true) {
                    $change === null => __('report.synthesis.variation_unknown'),
                    $change > 0 => __('report.synthesis.variation_up', ['count' => $change]),
                    $change < 0 => __('report.synthesis.variation_down', ['count' => -$change]),
                    default => __('report.synthesis.variation_none'),
                },
            ]),
            __('report.synthesis.movements', ['new' => $totals['new_total'], 'terminated' => $totals['terminated_total']]),
            $teeDue > 0
                ? __('report.synthesis.tee', ['paid' => DocumentFormat::amount($teePaid), 'due' => DocumentFormat::amount($teeDue), 'rate' => DocumentFormat::rate(round($teePaid / $teeDue * 100, 1))])
                : __('report.synthesis.tee_none'),
            __('report.synthesis.rme', ['total' => DocumentFormat::amount($rmePaid)]),
            __('report.synthesis.closing', [
                'smt' => (int) ($content['smt_transmitted'] ?? 0),
                'balance_sheets' => (int) ($content['balance_sheets_transmitted'] ?? 0),
            ]),
        ];

        return implode(' ', array_map(static fn (mixed $sentence): string => (string) $sentence, $sentences));
    }
}
