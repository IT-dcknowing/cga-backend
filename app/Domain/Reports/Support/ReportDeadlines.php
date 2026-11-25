<?php

namespace App\Domain\Reports\Support;

use App\Support\Config\SettingsService;
use Carbon\CarbonImmutable;

/**
 * Échéances du rapport trimestriel (RG-RPT-06, 07, CR-09) : date limite J — par défaut la fin du mois
 * qui suit le trimestre (30 avril, 31 juillet, 31 octobre, 31 janvier), règle du réseau
 * (report.deadline_rule) — et échéance réglementaire stricte J + report.legal_offset_days.
 */
final class ReportDeadlines
{
    public function __construct(private readonly SettingsService $settings) {}

    public function deadline(int $year, int $quarter): CarbonImmutable
    {
        /** @var array{months_after: int, day: int} $rule */
        $rule = (array) $this->settings->get('report.deadline_rule');
        $quarterEnd = CarbonImmutable::create($year, $quarter * 3, 1)->endOfMonth()->startOfDay();
        $month = $quarterEnd->startOfMonth()->addMonthsNoOverflow((int) $rule['months_after']);
        $day = (int) $rule['day'];

        return $day === 0 || $day > $month->daysInMonth ? $month->endOfMonth()->startOfDay() : $month->day($day);
    }

    public function legalDeadline(CarbonImmutable $deadline): CarbonImmutable
    {
        return $deadline->addDays((int) $this->settings->get('report.legal_offset_days'));
    }
}
