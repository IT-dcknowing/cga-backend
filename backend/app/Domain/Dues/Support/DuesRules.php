<?php

namespace App\Domain\Dues\Support;

use App\Domain\Dues\Enums\DuesStatus;
use App\Domain\Organizations\Models\Organization;
use App\Support\Config\SettingsService;
use Carbon\CarbonImmutable;

/**
 * Règles de la cotisation CNCGA (PRD M14) : prorata temporis semestriel d'un CGA agréé en cours d'année
 * (RG-COT-02 : agréé au 2e semestre, la moitié ; dues.prorata), date butoir de l'année (dues.deadline,
 * RG-COT-01) et statut (RG-COT-04).
 */
final class DuesRules
{
    public function __construct(private readonly SettingsService $settings) {}

    /** Part de la cotisation due pour l'année : 1, ou 0,5 pour un CGA agréé au 2e semestre. */
    public function prorata(Organization $cga, int $year): float
    {
        if ($this->settings->get('dues.prorata') !== 'semester' || $cga->agreement_date === null) {
            return 1.0;
        }
        $agreed = CarbonImmutable::parse($cga->agreement_date);

        return $agreed->year === $year && $agreed->month > 6 ? 0.5 : 1.0;
    }

    public function deadline(int $year): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $year.'-'.$this->settings->get('dues.deadline'));
    }

    /**
     * Reste nul : À jour ; réglé entre zéro et le dû : Partielle ; rien de réglé après la date butoir :
     * Impayée ; sinon Due.
     */
    public static function status(int $due, int $paid, CarbonImmutable $deadline, CarbonImmutable $today): DuesStatus
    {
        return match (true) {
            $paid >= $due => DuesStatus::Paid,
            $paid > 0 => DuesStatus::Partial,
            $today->greaterThan($deadline) => DuesStatus::Unpaid,
            default => DuesStatus::Due,
        };
    }
}
