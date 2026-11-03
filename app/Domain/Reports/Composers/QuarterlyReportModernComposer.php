<?php

namespace App\Domain\Reports\Composers;

use App\Domain\Documents\Composers\DocumentComposer;
use App\Domain\Documents\Data\DocumentContent;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Documents\Support\DocumentFormat;
use App\Domain\Reports\Support\ReportCharts;

/**
 * Rapport trimestriel modernisé (RG-RPT-09 à 12, 02-TRD §8.4) : la présentation change, les agrégats
 * réglementaires restent ceux du classeur — tous lus dans le même objet de données de la version. Reprend
 * le contenu du rapport Word (identité, capital humain, moyens, activités, mouvements) et y ajoute
 * l'analyse qualitative du CGA, les tableaux par CDI (adhérents, recouvrement de la TEE), les paiements RME
 * par nature, les graphiques et la source de chaque donnée.
 */
class QuarterlyReportModernComposer implements DocumentComposer
{
    public function __construct(private readonly QuarterlyReportDocxComposer $word) {}

    public function compose(GeneratedDocument $document): DocumentContent
    {
        $data = ReportSnapshot::of($document);
        $base = $this->word->compose($document);
        $sheets = $data['states']['sheets'];
        $analysis = (array) $data['qualitative_analysis'];
        $text = static fn (?string $value): string => DocumentFormat::text($value);

        $tee = array_map(static fn (array $group): array => [
            'tee_office' => $group['tax_office']['name'] ?? (string) __('report.no_tax_office'),
            'tee_due' => DocumentFormat::amount((int) ($group['totals']['quarter_net'] ?? 0)),
            'tee_paid' => DocumentFormat::amount((int) ($group['totals']['quarter_paid'] ?? 0)),
            'tee_balance' => DocumentFormat::amount((int) ($group['totals']['quarter_balance'] ?? 0)),
            'tee_rate' => self::rate((int) ($group['totals']['quarter_paid'] ?? 0), (int) ($group['totals']['quarter_net'] ?? 0)),
        ], $sheets['tee_follow_up']);
        $teeDue = (int) array_sum(array_map(static fn (array $group): int => (int) ($group['totals']['quarter_net'] ?? 0), $sheets['tee_follow_up']));
        $teePaid = (int) array_sum(array_map(static fn (array $group): int => (int) ($group['totals']['quarter_paid'] ?? 0), $sheets['tee_follow_up']));

        $rme = [];
        foreach (array_keys((array) config('reports.rme_columns')) as $column) {
            $rme[] = [
                'rme_nature' => (string) __('report.rme_columns.'.$column),
                'rme_amount' => DocumentFormat::amount((int) array_sum(array_map(static fn (array $group): int => (int) ($group['totals'][$column] ?? 0), $sheets['rme_follow_up']))),
            ];
        }
        $rmeTotal = (int) array_sum(array_map(static fn (array $group): int => (int) ($group['totals']['total'] ?? 0), $sheets['rme_follow_up']));

        $summary = array_map(static fn (array $row): array => [
            'summary_office' => $row['tax_office']['name'] ?? (string) __('report.no_tax_office'),
            'summary_active_tee' => (string) $row['active_tee'],
            'summary_active_rme' => (string) $row['active_rme'],
            'summary_new_total' => (string) $row['new_total'],
            'summary_terminated_total' => (string) $row['terminated_total'],
        ], $sheets['summary']['rows']);
        $empty = static fn (array $keys): array => [array_fill_keys($keys, $text(null))];

        $values = [
            ...$base->values,
            'synthesis' => $text($analysis['synthesis'] ?? null),
            'highlights' => $text($analysis['highlights'] ?? null),
            'outlook' => $text($analysis['outlook'] ?? null),
            'tee_due_total' => DocumentFormat::amount($teeDue),
            'tee_paid_total' => DocumentFormat::amount($teePaid),
            'tee_rate_total' => self::rate($teePaid, $teeDue),
            'rme_total' => DocumentFormat::amount($rmeTotal),
            'data_sources' => $this->sources((array) $data['states']['sources']),
        ];

        return new DocumentContent($values, $base->images, [
            'summary_office' => $summary !== [] ? $summary : $empty(['summary_office', 'summary_active_tee', 'summary_active_rme', 'summary_new_total', 'summary_terminated_total']),
            'tee_office' => $tee !== [] ? $tee : $empty(['tee_office', 'tee_due', 'tee_paid', 'tee_balance', 'tee_rate']),
            'rme_nature' => $rme,
            'director_name' => $base->rows['director_name'],
            'employee_name' => $base->rows['employee_name'],
            'movement_name' => $base->rows['movement_name'],
        ], array_map(ReportCharts::toChart(...), (array) $data['charts']));
    }

    private static function rate(int $paid, int $due): string
    {
        return $due > 0 ? DocumentFormat::rate(round($paid / $due * 100, 1)) : DocumentFormat::text(null);
    }

    /**
     * Source de chaque donnée (RG-RPT-12) : nombre de chiffres d'affaires et de paiements du trimestre
     * venus de la plateforme, d'un import ou de Compta Flow.
     *
     * @param  array{revenues: array<string, int>, payments: array<string, int>}  $sources
     */
    private function sources(array $sources): string
    {
        $list = static fn (array $counts): string => $counts === []
            ? (string) __('report.sources.none')
            : implode(', ', array_map(static fn (string $source, int $count): string => (string) __('report.sources.item', ['source' => __('report.sources.kinds.'.$source), 'count' => $count]), array_keys($counts), $counts));

        return (string) __('report.sources.text', ['revenues' => $list($sources['revenues']), 'payments' => $list($sources['payments'])]);
    }
}
