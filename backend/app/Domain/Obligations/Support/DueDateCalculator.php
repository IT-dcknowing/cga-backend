<?php

namespace App\Domain\Obligations\Support;

use App\Domain\Referentials\Models\Holiday;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Date d'échéance d'une période (F-OBL-03) : la règle du catalogue (`DueRule`) appliquée à la fin de
 * la période, puis reportée au jour ouvré suivant si elle tombe un samedi, un dimanche ou un jour
 * férié du calendrier tenu par le CNCGA (RG-OBL-06).
 */
final class DueDateCalculator
{
    /**
     * Jours fériés lus une fois par calcul en série.
     *
     * @var array<string, true>|null
     */
    private ?array $holidays = null;

    /**
     * @param  array<string, mixed>  $rule  règle d'échéance (`obligation_types.due_rule`)
     */
    public function dueDate(array $rule, ObligationPeriod $period): CarbonImmutable
    {
        return $this->nextBusinessDay($this->ruleDate($rule, $period->end));
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    public function ruleDate(array $rule, CarbonImmutable $periodEnd): CarbonImmutable
    {
        return match ($rule['type'] ?? null) {
            DueRule::DAY_OF_NEXT_MONTH => $this->dayOf($periodEnd->startOfMonth()->addMonthNoOverflow(), (int) $rule['day']),
            DueRule::FIXED_DATE => $this->fixedDate($periodEnd, (int) $rule['month'], (int) $rule['day']),
            DueRule::DAY_AFTER_PERIOD_END => $periodEnd->addDays((int) $rule['days']),
            default => throw new InvalidArgumentException('Règle d\'échéance inconnue.'),
        };
    }

    /**
     * Le jour même s'il est ouvré, sinon le premier jour ouvré qui suit (RG-OBL-06).
     */
    public function nextBusinessDay(CarbonImmutable $day): CarbonImmutable
    {
        while ($day->isWeekend() || isset($this->holidays()[$day->toDateString()])) {
            $day = $day->addDay();
        }

        return $day;
    }

    /**
     * Le jour du mois, ramené au dernier jour pour un mois plus court (le 31 en février : le 28 ou 29).
     */
    private function dayOf(CarbonImmutable $month, int $day): CarbonImmutable
    {
        return $month->setDay(min($day, $month->daysInMonth));
    }

    /**
     * Le `day` du mois `month` qui suit la fin de la période : de la même année, ou de la suivante.
     */
    private function fixedDate(CarbonImmutable $periodEnd, int $month, int $day): CarbonImmutable
    {
        $candidate = $this->dayOf($periodEnd->setDate($periodEnd->year, $month, 1), $day);

        return $candidate->greaterThan($periodEnd) ? $candidate : $this->dayOf($periodEnd->setDate($periodEnd->year + 1, $month, 1), $day);
    }

    /**
     * @return array<string, true>
     */
    private function holidays(): array
    {
        return $this->holidays ??= array_fill_keys(Holiday::query()->pluck('date')
            ->map(static fn (mixed $date): string => CarbonImmutable::parse((string) $date)->toDateString())
            ->all(), true);
    }
}
