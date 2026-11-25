<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Members\Enums\FeeKind;
use App\Domain\Members\Enums\FeesPeriodicity;
use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Members\Models\FeeSchedule;
use App\Domain\Payments\Support\FeeScale;
use Illuminate\Support\Facades\DB;

/**
 * Barème du CGA (F-PAY-01) : droit d'adhésion, honoraires et périodicité par régime. Un montant changé
 * prend effet ce jour (nouvelle ligne datée, l'ancienne reste dans l'historique) ; les adhérents déjà
 * inscrits gardent leur montant contractuel (RG-PAY-01).
 */
class SaveFeeSchedule
{
    public function __construct(private readonly FeeScale $scale) {}

    /**
     * @param  array<string, array{amount: int, periodicity: FeesPeriodicity}>  $fees  honoraires par régime (RME, TEE)
     */
    public function execute(string $organizationId, int $entryFee, array $fees): void
    {
        DB::transaction(function () use ($organizationId, $entryFee, $fees): void {
            if ($entryFee !== $this->scale->entryFee() || $this->scale->schedule(FeeKind::EntryFee, null) === null) {
                $this->write($organizationId, FeeKind::EntryFee, null, $entryFee, null);
            }
            foreach ($fees as $regime => $fee) {
                $regime = TaxRegime::from($regime);
                $current = $this->scale->schedule(FeeKind::Fees, $regime);
                if ($current === null || $current->amount !== $fee['amount'] || $current->periodicity !== $fee['periodicity']) {
                    $this->write($organizationId, FeeKind::Fees, $regime, $fee['amount'], $fee['periodicity']);
                }
            }
            $this->scale->forget();
        });
    }

    private function write(string $organizationId, FeeKind $kind, ?TaxRegime $regime, int $amount, ?FeesPeriodicity $periodicity): void
    {
        $schedule = FeeSchedule::query()->where('kind', $kind)->where('regime', $regime)->whereDate('valid_from', today())->first() ?? new FeeSchedule;
        $schedule->organization_id = $organizationId;
        $schedule->kind = $kind;
        $schedule->regime = $regime;
        $schedule->amount = $amount;
        $schedule->periodicity = $periodicity;
        $schedule->valid_from = today();
        $schedule->save();
    }
}
