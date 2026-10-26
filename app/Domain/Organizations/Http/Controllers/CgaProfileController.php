<?php

namespace App\Domain\Organizations\Http\Controllers;

use App\Domain\Organizations\Actions\StoreCgaImage;
use App\Domain\Organizations\Actions\UpdateCgaProfile;
use App\Domain\Organizations\Enums\CgaImageKind;
use App\Domain\Organizations\Http\Requests\CgaImageRequest;
use App\Domain\Organizations\Http\Requests\CgaProfileRequest;
use App\Domain\Organizations\Http\Resources\CgaProfileResource;
use App\Domain\Organizations\Models\Organization;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/cga/profile : fiche du CGA de l'espace actif (F-ORG-03) — consultation, complément par le
 * gérant, téléversement du logo, de la signature et du cachet.
 */
class CgaProfileController extends BaseApiController
{
    public function show(TenantContext $context): CgaProfileResource|JsonResponse
    {
        return $this->handle(function () use ($context): CgaProfileResource {
            $organization = $context->organization();
            Gate::authorize('viewProfile', $organization);

            return $this->resource($organization);
        }, action: 'cga.profile.show');
    }

    public function update(CgaProfileRequest $request, TenantContext $context, UpdateCgaProfile $update): CgaProfileResource|JsonResponse
    {
        return $this->handle(function () use ($request, $context, $update): CgaProfileResource {
            $organization = $update->execute($context->organization(), $request->validated());
            $this->logSubject($organization);

            return $this->resource($organization);
        }, action: 'cga.profile.update');
    }

    public function storeImage(CgaImageRequest $request, CgaImageKind $kind, TenantContext $context, StoreCgaImage $store): CgaProfileResource|JsonResponse
    {
        return $this->handle(function () use ($request, $kind, $context, $store): CgaProfileResource {
            $organization = $store->execute($context->organization(), $kind, $request->uploadedImage(), $this->currentUser());
            $this->logSubject($organization);

            return $this->resource($organization);
        }, action: 'cga.profile.images.store');
    }

    private function resource(Organization $organization): CgaProfileResource
    {
        return new CgaProfileResource($organization->load(['regionalDirectorate', 'taxOffice', 'commune', 'logo', 'signature', 'stamp']));
    }
}
