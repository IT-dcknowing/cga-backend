<?php

namespace App\Domain\Payments\Http\Controllers;

use App\Domain\Members\Models\Member;
use App\Domain\Payments\Actions\DeclarePayment;
use App\Domain\Payments\Http\Requests\DeclarePaymentRequest;
use App\Domain\Payments\Http\Resources\ChargeResource;
use App\Domain\Payments\Http\Resources\PaymentResource;
use App\Domain\Payments\Models\MemberCharge;
use App\Domain\Payments\Models\MemberPayment;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/member/charges et /payments : honoraires de l'adhérent dans son espace (/adherent/honoraires,
 * F-PAY-03) — ses sommes dues, ses paiements, « Déclarer un paiement ».
 */
class MemberSpacePaymentController extends BaseApiController
{
    public function __construct(private readonly TenantContext $context) {}

    public function charges(): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function (): AnonymousResourceCollection {
            Gate::authorize('declare', MemberPayment::class);

            return ChargeResource::collection(MemberCharge::query()->where('member_id', $this->context->memberId())->orderByDesc('due_date')->get());
        }, action: 'member.charges');
    }

    public function index(): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function (): AnonymousResourceCollection {
            Gate::authorize('declare', MemberPayment::class);

            return PaymentResource::collection(MemberPayment::query()->with(['proof', 'allocations'])->where('member_id', $this->context->memberId())->orderByDesc('paid_on')->orderByDesc('created_at')->get());
        }, action: 'member.payments.index');
    }

    public function store(DeclarePaymentRequest $request, DeclarePayment $declare): JsonResponse
    {
        return $this->handle(function () use ($request, $declare): JsonResponse {
            $member = Member::query()->findOrFail($this->context->memberId());
            $payment = $declare->execute($member, $request->entry(), $request->proof(), $this->currentUser());
            $this->logSubject($payment);

            return (new PaymentResource($payment->load(['proof', 'allocations'])))->response()->setStatusCode(201);
        }, action: 'member.payments.store');
    }
}
