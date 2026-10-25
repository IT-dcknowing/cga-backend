<?php

namespace App\Domain\Payments\Http\Controllers;

use App\Domain\Payments\Actions\SaveInvoicingProvider;
use App\Domain\Payments\Actions\TestInvoicingProvider;
use App\Domain\Payments\Http\Requests\SaveInvoicingProviderRequest;
use App\Domain\Payments\Http\Resources\InvoicingProviderResource;
use App\Domain\Payments\Models\InvoicingProvider;
use App\Domain\Payments\Support\InvoicingMode;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/cga/invoicing-provider : onglet « Facturation FNE » de /cga/parametres (F-ADM-07,
 * RG-FNE-03) — mode de certification, adresse et identifiants du service, contrôle du n° FNE,
 * interrupteur, bouton « Tester ».
 */
class InvoicingProviderController extends BaseApiController
{
    public function __construct(private readonly TenantContext $context) {}

    public function show(InvoicingMode $modes): InvoicingProviderResource|JsonResponse
    {
        return $this->handle(function () use ($modes): InvoicingProviderResource {
            Gate::authorize('view', InvoicingProvider::class);

            return new InvoicingProviderResource($modes->provider($this->context->organizationId()));
        }, action: 'invoicing_provider.show');
    }

    /**
     * Le paramétrage est remplacé, qu'il existe déjà ou non : toujours 200.
     */
    public function update(SaveInvoicingProviderRequest $request, SaveInvoicingProvider $save): JsonResponse
    {
        return $this->handle(function () use ($request, $save): JsonResponse {
            $provider = $save->execute($this->context->organizationId(), $request->mode(), $request->baseUrl(), $request->credentials(), $request->pattern(), $request->active(), $this->currentUser());
            $this->logSubject($provider);

            return (new InvoicingProviderResource($provider))->response()->setStatusCode(200);
        }, action: 'invoicing_provider.update');
    }

    public function test(TestInvoicingProvider $test): JsonResponse
    {
        return $this->handle(function () use ($test): JsonResponse {
            Gate::authorize('manage', InvoicingProvider::class);

            return (new InvoicingProviderResource($test->execute($this->context->organizationId())))->response()->setStatusCode(200);
        }, action: 'invoicing_provider.test');
    }
}
