<?php

namespace App\Domain\Dues\Http\Controllers;

use App\Domain\Dues\Actions\DuesPayments;
use App\Domain\Dues\Http\Requests\DuesPaymentRequest;
use App\Domain\Dues\Http\Resources\NetworkDueResource;
use App\Domain\Dues\Models\NetworkDue;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/cga/dues : la cotisation du centre au CNCGA (/cga/cotisation, F-COT-06) — ses cotisations,
 * paiements et reçus ; « Déclarer un paiement » avec sa preuve (F-COT-03).
 */
class CgaDuesController extends BaseApiController
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function (): AnonymousResourceCollection {
            Gate::authorize('viewOwn', NetworkDue::class);
            $dues = self::withNetworkDocuments(NetworkDue::query()->with('payments.proof')->orderByDesc('year')->get());

            return NetworkDueResource::collection($dues->map(
                static fn (NetworkDue $due): NetworkDueResource => (new NetworkDueResource($due))->withPayments()->forDebtor(),
            ))->additional(['meta' => ['can' => ['declare' => Gate::allows('declare', NetworkDue::class)]]]);
        }, action: 'cga_dues.index');
    }

    public function declare(DuesPaymentRequest $request, NetworkDue $networkDue, DuesPayments $payments): JsonResponse
    {
        return $this->handle(function () use ($request, $networkDue, $payments): JsonResponse {
            $payment = $payments->declare($networkDue, $request->amount(), $request->paidOn(), $request->paymentMethod(), $request->reference(), $request->proof(), $this->context->membership());
            $this->logSubject($payment);
            $due = self::withNetworkDocuments(new Collection([$networkDue->fresh('payments.proof') ?? $networkDue]))->firstOrFail();

            return (new NetworkDueResource($due))->withPayments()->forDebtor()->response()->setStatusCode(201);
        }, action: 'cga_dues.declare');
    }

    /**
     * Reçus et facture sont des documents du CNCGA : chargés hors cloisonnement, pour les seules cotisations
     * du centre déjà lues dans son espace.
     *
     * @param  Collection<int, NetworkDue>  $dues
     * @return Collection<int, NetworkDue>
     */
    private static function withNetworkDocuments(Collection $dues): Collection
    {
        return Tenancy::bypass(
            static fn (): Collection => $dues->load(['invoice.document.pdf', 'payments.receipt.pdf']),
            'Cotisation : reçus et facture du CNCGA remis au centre débiteur.',
        );
    }
}
