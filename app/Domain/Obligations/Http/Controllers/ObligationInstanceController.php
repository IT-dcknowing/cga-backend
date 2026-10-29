<?php

namespace App\Domain\Obligations\Http\Controllers;

use App\Domain\Members\Models\Member;
use App\Domain\Obligations\Actions\AddOtherAuthorityInstance;
use App\Domain\Obligations\Actions\MarkDeclarationDone;
use App\Domain\Obligations\Actions\RecordObligationPayment;
use App\Domain\Obligations\Actions\SetDiligenceStatus;
use App\Domain\Obligations\Actions\SetInstanceAmount;
use App\Domain\Obligations\Enums\ObligationCategory;
use App\Domain\Obligations\Http\Requests\DiligenceStatusRequest;
use App\Domain\Obligations\Http\Requests\InstanceAmountRequest;
use App\Domain\Obligations\Http\Requests\ListObligationInstancesRequest;
use App\Domain\Obligations\Http\Requests\MarkDeclarationDoneRequest;
use App\Domain\Obligations\Http\Requests\OtherAuthorityInstanceRequest;
use App\Domain\Obligations\Http\Requests\RecordObligationPaymentRequest;
use App\Domain\Obligations\Http\Resources\ObligationInstanceResource;
use App\Domain\Obligations\Models\MemberObligation;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Domain\Obligations\Support\ObligationSummary;
use App\Support\Http\BaseApiController;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/cga/members/{member}/obligation-instances : onglet Obligations du dossier (F-OBL-04) — échéances
 * des 4 blocs avec leur « Total dû » (RG-OBL-08), « Marquer faite » (RG-OBL-05), « Saisir le montant
 * dû », « Enregistrer un paiement » (RG-OBL-07), avancement des diligences, « + Autre administration ».
 */
class ObligationInstanceController extends BaseApiController
{
    public function index(ListObligationInstancesRequest $request, Member $member): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($request, $member): AnonymousResourceCollection {
            $instances = ObligationInstance::query()
                ->with('payments.proof')
                ->where('member_id', $member->id)
                ->when($request->category(), static fn (Builder $query, ObligationCategory $category) => $query->where('category', $category))
                ->when($request->dueFrom(), static fn (Builder $query, CarbonImmutable $from) => $query->where('due_date', '>=', $from->toDateString()))
                ->when($request->dueTo(), static fn (Builder $query, CarbonImmutable $to) => $query->where('due_date', '<=', $to->toDateString()))
                ->orderBy('due_date')
                ->orderBy('kind')
                ->orderBy('label')
                ->get();
            $manages = Gate::allows('manage', [MemberObligation::class, $member]);

            return ObligationInstanceResource::collection($instances->map(
                static fn (ObligationInstance $instance): ObligationInstanceResource => (new ObligationInstanceResource($instance))->managedBy($manages),
            ))->additional(['summary' => ObligationSummary::byCategory($instances, CarbonImmutable::today())]);
        }, action: 'obligation_instances.index');
    }

    public function store(OtherAuthorityInstanceRequest $request, Member $member, AddOtherAuthorityInstance $add): JsonResponse
    {
        return $this->handle(function () use ($request, $member, $add): JsonResponse {
            $instance = $add->execute($member, $request->authority(), $request->label(), $request->amountDue(), $request->dueDate());
            $this->logSubject($instance);

            return $this->resource($instance)->response()->setStatusCode(201);
        }, action: 'obligation_instances.store');
    }

    public function done(MarkDeclarationDoneRequest $request, Member $member, ObligationInstance $obligationInstance, MarkDeclarationDone $done): ObligationInstanceResource|JsonResponse
    {
        return $this->handle(function () use ($request, $obligationInstance, $done): ObligationInstanceResource {
            $payment = $done->execute($obligationInstance, $request->doneOn(), $request->amountDue(), $request->amountGross());
            $this->logSubject($obligationInstance);

            return $this->resource($obligationInstance->refresh())
                ->additional(['payment_instance' => $payment === null ? null : $this->resource($payment)]);
        }, action: 'obligation_instances.done');
    }

    public function amount(InstanceAmountRequest $request, Member $member, ObligationInstance $obligationInstance, SetInstanceAmount $amount): ObligationInstanceResource|JsonResponse
    {
        return $this->handle(function () use ($request, $obligationInstance, $amount): ObligationInstanceResource {
            $instance = $amount->execute($obligationInstance, $request->amountDue(), $request->amountGross());
            $this->logSubject($instance);

            return $this->resource($instance);
        }, action: 'obligation_instances.amount');
    }

    public function payment(RecordObligationPaymentRequest $request, Member $member, ObligationInstance $obligationInstance, RecordObligationPayment $record): JsonResponse
    {
        return $this->handle(function () use ($request, $obligationInstance, $record): JsonResponse {
            $payment = $record->execute($obligationInstance, $request->amount(), $request->paidOn(), $request->proof(), $this->currentUser());
            $this->logSubject($payment);

            return $this->resource($obligationInstance->refresh())->response()->setStatusCode(201);
        }, action: 'obligation_instances.payment');
    }

    public function diligence(DiligenceStatusRequest $request, Member $member, ObligationInstance $obligationInstance, SetDiligenceStatus $diligence): ObligationInstanceResource|JsonResponse
    {
        return $this->handle(function () use ($request, $obligationInstance, $diligence): ObligationInstanceResource {
            $instance = $diligence->execute($obligationInstance, $request->status(), CarbonImmutable::today());
            $this->logSubject($instance);

            return $this->resource($instance);
        }, action: 'obligation_instances.diligence');
    }

    private function resource(ObligationInstance $instance): ObligationInstanceResource
    {
        return (new ObligationInstanceResource($instance->load('payments.proof')))->managedBy(true);
    }
}
