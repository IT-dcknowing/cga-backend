<?php

namespace App\Domain\Payments\Http\Controllers;

use App\Domain\Members\Models\Member;
use App\Domain\Payments\Actions\ConfirmPayment;
use App\Domain\Payments\Actions\RecordPayment;
use App\Domain\Payments\Actions\RejectPayment;
use App\Domain\Payments\Http\Requests\ConfirmPaymentRequest;
use App\Domain\Payments\Http\Requests\RecordPaymentRequest;
use App\Domain\Payments\Http\Requests\RejectPaymentRequest;
use App\Domain\Payments\Http\Resources\ChargeResource;
use App\Domain\Payments\Http\Resources\PaymentResource;
use App\Domain\Payments\Models\MemberCharge;
use App\Domain\Payments\Models\MemberPayment;
use App\Support\Http\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/cga/members/{member}/charges et /payments : onglet « Honoraires et paiements » du dossier
 * (F-PAY-02, 03) — sommes dues, paiements, « Enregistrer un paiement », confirmation ou rejet d'un
 * paiement déclaré par l'adhérent.
 */
class MemberPaymentController extends BaseApiController
{
    public function charges(Member $member): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($member): AnonymousResourceCollection {
            Gate::authorize('viewAny', [MemberPayment::class, $member]);

            return ChargeResource::collection(MemberCharge::query()->where('member_id', $member->id)->orderByDesc('due_date')->orderByDesc('created_at')->get());
        }, action: 'payments.charges');
    }

    public function index(Member $member): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($member): AnonymousResourceCollection {
            Gate::authorize('viewAny', [MemberPayment::class, $member]);

            return PaymentResource::collection(MemberPayment::query()->with(['proof', 'allocations'])->where('member_id', $member->id)->orderByDesc('paid_on')->orderByDesc('created_at')->get());
        }, action: 'payments.index');
    }

    public function store(RecordPaymentRequest $request, Member $member, RecordPayment $record): JsonResponse
    {
        return $this->handle(function () use ($request, $member, $record): JsonResponse {
            $payment = $record->execute($member, $request->entry(), $request->chargeIds(), $request->proof(), $this->currentUser());
            $this->logSubject($payment);

            return (new PaymentResource($payment->load(['proof', 'allocations'])))->response()->setStatusCode(201);
        }, action: 'payments.store');
    }

    public function confirm(ConfirmPaymentRequest $request, Member $member, MemberPayment $payment, ConfirmPayment $confirm): PaymentResource|JsonResponse
    {
        return $this->handle(function () use ($request, $payment, $confirm): PaymentResource {
            $payment = $confirm->execute($payment, $request->chargeIds(), $this->currentUser());
            $this->logSubject($payment);

            return new PaymentResource($payment->load(['proof', 'allocations']));
        }, action: 'payments.confirm');
    }

    public function reject(RejectPaymentRequest $request, Member $member, MemberPayment $payment, RejectPayment $reject): PaymentResource|JsonResponse
    {
        return $this->handle(function () use ($request, $payment, $reject): PaymentResource {
            $payment = $reject->execute($payment, $request->reason(), $this->currentUser());
            $this->logSubject($payment);

            return new PaymentResource($payment->load(['proof', 'allocations']));
        }, action: 'payments.reject');
    }
}
