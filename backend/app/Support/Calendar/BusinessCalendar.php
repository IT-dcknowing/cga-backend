<?php

namespace App\Support\Calendar;

use App\Domain\Referentials\Models\Holiday;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Jours ouvrés (02-TRD §7.3) : ni samedi, ni dimanche, ni jour férié du calendrier du CNCGA.
 * Une échéance qui tombe un jour non ouvré est reportée au jour ouvré suivant (RG-OBL-06).
 */
class BusinessCalendar
{
    /**
     * @var array<string, true>|null
     */
    private ?array $holidays = null;

    public function isBusinessDay(CarbonInterface $date): bool
    {
        return ! $date->isWeekend() && ! isset($this->holidays()[$date->toDateString()]);
    }

    /**
     * Le jour lui-même s'il est ouvré, sinon le premier jour ouvré qui suit.
     */
    public function nextBusinessDay(CarbonInterface $date): CarbonImmutable
    {
        $day = CarbonImmutable::instance($date)->startOfDay();

        while (! $this->isBusinessDay($day)) {
            $day = $day->addDay();
        }

        return $day;
    }

    /**
     * Relit le calendrier (après l'ajout, la modification ou la suppression d'un jour férié).
     */
    public function forget(): void
    {
        $this->holidays = null;
    }

    /**
     * @return array<string, true>
     */
    private function holidays(): array
    {
        return $this->holidays ??= Holiday::query()
            ->get(['date'])
            ->mapWithKeys(static fn (Holiday $holiday): array => [$holiday->date->toDateString() => true])
            ->all();
    }
}
