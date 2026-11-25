<?php

namespace App\Domain\Dues\Http\Controllers;

use App\Domain\Dues\Actions\DuesPayments;
use App\Domain\Dues\Actions\DuesReminders;
use App\Domain\Dues\Actions\IssueDuesInvoice;
use App\Domain\Dues\Http\Requests\DuesPaymentRequest;
use App\Domain\Dues\Http\Requests\NetworkDuesRequest;
use App\Domain\Dues\Http\Requests\RejectDuesPaymentRequest;
use App\Domain\Dues\Http\Resources\NetworkDueResource;
use App\Domain\Dues\Models\NetworkDue;
use App\Domain\Dues\Models\NetworkDuePayment;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * /api/v1/network/dues : cotisations vues du CNCGA (/cncga/cotisations, F-COT-02 à 05) — tableau de
 * l'année, détail d'une cotisation, saisie directe d'un paiement, confirmation (reçu) ou rejet d'un
 * paiement déclaré, relance manuelle. Le CNCGA lit les cotisations de tous les centres par un
 * contournement nommé (05-BACKEND-SCHEMA §10) ; aucune suspension n'est jamais automatique (RG-COT-06).
 */
class NetworkDuesController extends BaseApiController
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(NetworkDuesRequest $request): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($request): AnonymousResourceCollection {
            $dues = Tenancy::bypass(static fn () => NetworkDue::query()
                ->with(['organization', 'payments', 'invoice'])
                ->when($request->year(), static fn ($query, int $year) => $query->where('year', $year))
                ->when($request->cga(), static fn ($query, string $cga) => $query->where('organization_id', $cga)->orderByDesc('year'))
                ->when($request->status(), static fn ($query, string $status) => $query->where('status', $status))
                ->get()
                ->sortBy(static fn (NetworkDue $due): string => mb_strtolower($due->organization->name))
                ->values(), 'Cotisations : tableau du CNCGA (F-COT-02).');

            return NetworkDueResource::collection($dues)->additional(['meta' => [
                'totals' => ['amount_due' => $dues->sum('amount_due'), 'amount_paid' => $dues->sum('amount_paid'), 'balance' => $dues->sum(static fn (NetworkDue $due): int => $due->balance())],
                'counts' => $dues->countBy(static fn (NetworkDue $due): string => $due->status->value)->all(),
            ]]);
        }, action: 'network_dues.index');
    }

    public function show(string $dueId): NetworkDueResource|JsonResponse
    {
        return $this->handle(function () use ($dueId): NetworkDueResource {
            Gate::authorize('viewNetwork', NetworkDue::class);

            return (new NetworkDueResource($this->due($dueId)))->withPayments();
        }, action: 'network_dues.show');
    }

    public function record(DuesPaymentRequest $request, string $dueId, DuesPayments $payments): JsonResponse
    {
        return $this->handle(function () use ($request, $dueId, $payments): JsonResponse {
            $payment = $payments->record($this->due($dueId), $request->amount(), $request->paidOn(), $request->paymentMethod(), $request->reference(), $this->context->membership());
            $this->logSubject($payment);

            return (new NetworkDueResource($this->due($payment->network_due_id)))->withPayments()->response()->setStatusCode(201);
        }, action: 'network_dues.record');
    }

    public function confirm(string $paymentId, DuesPayments $payments): NetworkDueResource|JsonResponse
    {
        return $this->handle(function () use ($paymentId, $payments): NetworkDueResource {
            Gate::authorize('manage', NetworkDue::class);
            $payment = $payments->confirm($this->payment($paymentId), $this->context->membership());

            return (new NetworkDueResource($this->due($payment->network_due_id)))->withPayments();
        }, action: 'network_dues.confirm');
    }

    public function reject(RejectDuesPaymentRequest $request, string $paymentId, DuesPayments $payments): NetworkDueResource|JsonResponse
    {
        return $this->handle(function () use ($request, $paymentId, $payments): NetworkDueResource {
            $payment = $payments->reject($this->payment($paymentId), $request->reason(), $this->context->membership());

            return (new NetworkDueResource($this->due($payment->network_due_id)))->withPayments();
        }, action: 'network_dues.reject');
    }

    public function remind(string $dueId, DuesReminders $reminders): NetworkDueResource|JsonResponse
    {
        return $this->handle(function () use ($dueId, $reminders): NetworkDueResource {
            Gate::authorize('remind', NetworkDue::class);
            $reminders->remind($this->due($dueId), CarbonImmutable::now());

            return (new NetworkDueResource($this->due($dueId)))->withPayments();
        }, action: 'network_dues.remind');
    }

    public function invoice(string $dueId, IssueDuesInvoice $issue): NetworkDueResource|JsonResponse
    {
        return $this->handle(function () use ($dueId, $issue): NetworkDueResource {
            Gate::authorize('invoice', NetworkDue::class);
            $invoice = $issue->execute($this->due($dueId), $this->context->membership());
            $this->logSubject($invoice);

            return (new NetworkDueResource($this->due($dueId)))->withPayments();
        }, action: 'network_dues.invoice');
    }

    /**
     * @throws NotFoundHttpException identifiant inconnu
     */
    private function due(string $id): NetworkDue
    {
        return (Str::isUlid($id) ? Tenancy::bypass(static fn (): ?NetworkDue => NetworkDue::query()->with(['organization', 'invoice.document.pdf', 'payments.proof', 'payments.receipt.pdf'])->find($id), 'Cotisations : lecture par le CNCGA.') : null)
            ?? throw new NotFoundHttpException;
    }

    /**
     * @throws NotFoundHttpException identifiant inconnu
     */
    private function payment(string $id): NetworkDuePayment
    {
        return (Str::isUlid($id) ? Tenancy::bypass(static fn (): ?NetworkDuePayment => NetworkDuePayment::query()->find($id), 'Cotisations : paiement lu par le CNCGA.') : null)
            ?? throw new NotFoundHttpException;
    }
}
