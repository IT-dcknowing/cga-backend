<?php

namespace App\Domain\Reports\Actions;

use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Reports\Events\ReportLate;
use App\Domain\Reports\Events\ReportLegallyLate;
use App\Domain\Reports\Events\ReportReminderDue;
use App\Domain\Reports\Models\QuarterlyReport;
use App\Domain\Reports\Support\ReportDeadlines;
use App\Support\Config\SettingsService;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * `reminders:reports` chaque jour (02-TRD §11, RG-RPT-06, 07) : pour les trimestres récents de chaque CGA
 * non résilié dont le rapport n'est pas transmis — relances aux jours paramétrés autour de la date
 * limite J, passage « en retard » au lendemain de J, « hors délai réglementaire » au lendemain de J+15
 * (indicateurs du rapport mis à jour, événements remis au moteur de notifications). Rejouable : une
 * relance déjà notée dans le rapport ne repart pas ; sans rapport, seule la date du jour compte.
 */
class SendReportReminders
{
    /** Trimestres passés examinés : la règle d'échéance va jusqu'à six mois après le trimestre. */
    private const QUARTERS_BACK = 3;

    public function __construct(
        private readonly ReportDeadlines $deadlines,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @return array{reminders: int, late: int, legally_late: int}
     */
    public function execute(CarbonImmutable $today): array
    {
        return Tenancy::bypass(function () use ($today): array {
            $totals = ['reminders' => 0, 'late' => 0, 'legally_late' => 0];
            /** @var list<int> $offsets */
            $offsets = array_map('intval', (array) $this->settings->get('report.reminder_offsets'));
            $periods = [];
            for ($back = 1; $back <= self::QUARTERS_BACK; $back++) {
                $day = $today->startOfQuarter()->subMonthsNoOverflow(3 * ($back - 1))->subDay();
                $periods[] = [$day->year, $day->quarter];
            }

            Organization::query()
                ->where('type', OrganizationType::Cga)
                ->where('status', '<>', OrganizationStatus::Terminated)
                ->orderBy('id')
                ->each(function (Organization $cga) use ($today, $offsets, $periods, &$totals): void {
                    foreach ($periods as [$year, $quarter]) {
                        $this->period($cga->id, $year, $quarter, $today, $offsets, $totals);
                    }
                });

            return $totals;
        }, 'Tâche reminders:reports : échéances des rapports trimestriels de tous les CGA (RG-RPT-06, 07).');
    }

    /**
     * @param  list<int>  $offsets
     * @param  array{reminders: int, late: int, legally_late: int}  $totals
     */
    private function period(string $organizationId, int $year, int $quarter, CarbonImmutable $today, array $offsets, array &$totals): void
    {
        $report = QuarterlyReport::query()->where('organization_id', $organizationId)->where('year', $year)->where('quarter', $quarter)->first();
        if ($report?->transmitted_at !== null) {
            return;
        }
        $deadline = $report === null ? $this->deadlines->deadline($year, $quarter) : CarbonImmutable::parse($report->deadline);
        $legal = $report === null ? $this->deadlines->legalDeadline($deadline) : CarbonImmutable::parse($report->legal_deadline);

        foreach ($offsets as $offset) {
            if ($today->isSameDay($deadline->addDays($offset)) && ! in_array($offset, $report->reminders_sent ?? [], true) && $this->once($organizationId, $year, $quarter, "reminder{$offset}")) {
                ReportReminderDue::dispatch($organizationId, $year, $quarter, $offset);
                $totals['reminders']++;
                if ($report !== null) {
                    $report->reminders_sent = [...$report->reminders_sent, $offset];
                }
            }
        }

        $late = $today->greaterThan($deadline);
        $legallyLate = $today->greaterThan($legal);
        if ($late && ($report === null ? $today->isSameDay($deadline->addDay()) : ! $report->is_late) && $this->once($organizationId, $year, $quarter, 'late')) {
            ReportLate::dispatch($organizationId, $year, $quarter);
            $totals['late']++;
        }
        if ($legallyLate && ($report === null ? $today->isSameDay($legal->addDay()) : ! $report->is_legally_late) && $this->once($organizationId, $year, $quarter, 'legally_late')) {
            ReportLegallyLate::dispatch($organizationId, $year, $quarter);
            $totals['legally_late']++;
        }

        if ($report !== null) {
            $report->is_late = $late;
            $report->is_legally_late = $legallyLate;
            $report->save();
        }
    }

    /**
     * Vrai la première fois seulement pour ce centre, ce trimestre et cet événement : une tâche relancée
     * le même jour ne renvoie rien, même sans rapport préparé.
     */
    private function once(string $organizationId, int $year, int $quarter, string $event): bool
    {
        return Cache::add("reports:deadline:{$organizationId}:{$year}-{$quarter}:{$event}", true, now()->addDays((int) config('reports.reminder_memory_days')));
    }
}
