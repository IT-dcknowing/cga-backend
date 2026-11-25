<?php

namespace App\Domain\Obligations\Support;

use App\Domain\Obligations\Enums\ObligationPeriodicity;
use Carbon\CarbonImmutable;

/**
 * Période d'une obligation (F-OBL-03) : début, fin et libellé, clé de l'idempotence de la génération
 * (`2026-10`, `2026-T3`, `2026-S1`, `2026` ; une obligation ponctuelle prend sa date de début).
 */
final readonly class ObligationPeriod
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public string $label,
    ) {}

    /**
     * Période de cette périodicité qui contient le jour donné ; nulle pour une obligation sur événement.
     */
    public static function containing(ObligationPeriodicity $periodicity, CarbonImmutable $day): ?self
    {
        return match ($periodicity) {
            ObligationPeriodicity::Monthly => new self($day->startOfMonth(), $day->endOfMonth()->startOfDay(), $day->format('Y-m')),
            ObligationPeriodicity::Quarterly => new self($day->startOfQuarter(), $day->endOfQuarter()->startOfDay(), $day->format('Y').'-T'.$day->quarter),
            ObligationPeriodicity::HalfYearly => self::half($day),
            ObligationPeriodicity::Yearly => new self($day->startOfYear(), $day->endOfYear()->startOfDay(), $day->format('Y')),
            ObligationPeriodicity::OneOff => new self($day->startOfDay(), $day->startOfDay(), $day->format('Y-m-d')),
            ObligationPeriodicity::OnEvent => null,
        };
    }

    /**
     * Période suivante de même périodicité.
     */
    public function next(ObligationPeriodicity $periodicity): ?self
    {
        return $periodicity === ObligationPeriodicity::OneOff ? null : self::containing($periodicity, $this->end->addDay());
    }

    private static function half(CarbonImmutable $day): self
    {
        $first = $day->month <= 6;
        $start = $day->setDate($day->year, $first ? 1 : 7, 1)->startOfDay();

        return new self($start, $start->addMonths(6)->subDay(), $day->format('Y').'-S'.($first ? 1 : 2));
    }
}
