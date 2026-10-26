<?php

namespace App\Domain\Referentials\Http\Controllers;

use App\Domain\Referentials\Actions\ProposeSector;
use App\Domain\Referentials\Http\Requests\ProposeSectorRequest;
use App\Domain\Referentials\Http\Resources\SectorResource;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/cga/sectors : secteur proposé par le CGA depuis l'assistant d'inscription (F-ADH-02,
 * RG-REF-01), aussitôt utilisable par lui.
 */
class SectorProposalController extends BaseApiController
{
    public function store(ProposeSectorRequest $request, ProposeSector $propose, TenantContext $context): JsonResponse
    {
        return $this->handle(function () use ($request, $propose, $context): JsonResponse {
            $sector = $propose->execute($context->organizationId(), $request->name());
            $this->logSubject($sector);

            return (new SectorResource($sector))->response()->setStatusCode(201);
        }, action: 'sectors.propose');
    }
}
