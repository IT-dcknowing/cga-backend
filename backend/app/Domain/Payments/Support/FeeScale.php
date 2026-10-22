<?php

namespace App\Domain\Payments\Support;

use App\Domain\Members\Enums\FeeKind;
use App\Domain\Members\Enums\FeesPeriodicity;
use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Members\Models\FeeSchedule;
use App\Domain\Members\Rules\RegimeOfPortfolio;
use App\Support\Config\SettingsService;

/**
 * Barème en vigueur du CGA de l'espace actif (F-PAY-01) : droit d'adhésion, honoraires par régime
 * (RME, TEE) et leur périodicité. Sans barème saisi : le droit d'adhésion par défaut du réseau
 * (fees.default_entry_fee), des honoraires à zéro, mensuels. Lu une fois par instance (import d'un lot).
 */
class FeeScale
{
    /**
     * @var array<string, FeeSchedule|null>
     */
    private array $schedules = [];

    public function __construct(private readonly SettingsService $settings) {}

    public function entryFee(): int
    {
        return $this->schedule(FeeKind::EntryFee, null)->amount ?? (int) $this->settings->get('fees.default_entry_fee');
    }

    public function fees(TaxRegime $regime): int
    {
        return in_array($regime, RegimeOfPortfolio::cga(), true) ? ($this->schedule(FeeKind::Fees, $regime)->amount ?? 0) : 0;
    }

    public function periodicity(TaxRegime $regime): FeesPeriodicity
    {
        $schedule = in_array($regime, RegimeOfPortfolio::cga(), true) ? $this->schedule(FeeKind::Fees, $regime) : null;

        return $schedule->periodicity ?? FeesPeriodicity::Monthly;
    }

    /**
     * Ligne du barème en vigueur aujourd'hui pour cette nature (et ce régime).
     */
    public function schedule(FeeKind $kind, ?TaxRegime $regime): ?FeeSchedule
    {
        $key = $kind->value.'|'.$regime?->value.'|'.today()->toDateString();
        if (! array_key_exists($key, $this->schedules)) {
            $this->schedules[$key] = FeeSchedule::query()
                ->where('kind', $kind)
                ->where('regime', $regime)
                ->where('valid_from', '<=', today())
                ->orderByDesc('valid_from')
                ->first();
        }

        return $this->schedules[$key];
    }

    /**
     * Oublie le barème lu (après sa modification).
     */
    public function forget(): void
    {
        $this->schedules = [];
    }
}
