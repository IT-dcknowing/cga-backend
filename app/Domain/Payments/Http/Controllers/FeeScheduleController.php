<?php

namespace App\Domain\Payments\Http\Controllers;

use App\Domain\Members\Enums\FeeKind;
use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Payments\Actions\SaveFeeSchedule;
use App\Domain\Payments\Http\Requests\FeeScheduleRequest;
use App\Domain\Payments\Models\MemberPayment;
use App\Domain\Payments\Support\FeeScale;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/cga/fee-schedule : barème du CGA (F-PAY-01, onglet « Barème » de /cga/parametres), qui
 * pré-remplit les montants de l'inscription.
 */
class FeeScheduleController extends BaseApiController
{
    public function show(FeeScale $scale): JsonResponse
    {
        return $this->handle(function () use ($scale): JsonResponse {
            Gate::authorize('viewFeeSchedule', MemberPayment::class);

            return $this->schedule($scale);
        }, action: 'fees.schedule.show');
    }

    public function update(FeeScheduleRequest $request, TenantContext $context, SaveFeeSchedule $save, FeeScale $scale): JsonResponse
    {
        return $this->handle(function () use ($request, $context, $save, $scale): JsonResponse {
            $save->execute($context->organizationId(), $request->entryFee(), $request->fees());
            $scale->forget();

            return $this->schedule($scale);
        }, action: 'fees.schedule.update');
    }

    private function schedule(FeeScale $scale): JsonResponse
    {
        $fees = [];
        foreach ([TaxRegime::Rme, TaxRegime::Tee] as $regime) {
            $fees[$regime->value] = [
                'amount' => $scale->fees($regime),
                'periodicity' => $scale->periodicity($regime)->value,
                'periodicity_label' => $scale->periodicity($regime)->label(),
                'valid_from' => $scale->schedule(FeeKind::Fees, $regime)?->valid_from->toDateString(),
            ];
        }

        return response()->json(['data' => [
            'entry_fee' => [
                'amount' => $scale->entryFee(),
                'valid_from' => $scale->schedule(FeeKind::EntryFee, null)?->valid_from->toDateString(),
            ],
            'fees' => $fees,
            'can' => ['update' => Gate::allows('updateFeeSchedule', MemberPayment::class)],
        ]]);
    }
}
