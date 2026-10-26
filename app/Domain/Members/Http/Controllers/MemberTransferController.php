<?php

namespace App\Domain\Members\Http\Controllers;

use App\Domain\Members\Actions\ReimportMemberIdentity;
use App\Domain\Members\Http\Requests\MemberTransferRequest;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * /api/v1/cga/members/transfers : réimport des données d'identification d'un adhérent venu d'un autre
 * CGA (F-ADH-10, RG-ADH-15). La réponse pré-remplit l'assistant d'inscription ; le dossier créé avec
 * transfer_id y est relié.
 */
class MemberTransferController extends BaseApiController
{
    public function __construct(private readonly TenantContext $context) {}

    public function store(MemberTransferRequest $request, ReimportMemberIdentity $reimport): JsonResponse
    {
        return $this->handle(function () use ($request, $reimport): JsonResponse {
            $result = $reimport->execute($this->context->organizationId(), $request->ncc(), $request->consent(), $this->currentUser());
            $this->logSubject($result['transfer']);

            return response()->json(['data' => [
                'transfer_id' => $result['transfer']->id,
                'identity' => $result['identity'],
            ]], 201);
        }, action: 'members.transfers.store');
    }
}
