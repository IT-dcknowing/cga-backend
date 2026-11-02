<?php

namespace App\Domain\Reports\Http\Controllers;

use App\Domain\Organizations\Actions\UpdateCgaProfile;
use App\Domain\Reports\Actions\SaveCgaPerson;
use App\Domain\Reports\Actions\SaveCgaResource;
use App\Domain\Reports\Enums\CgaResourceCategory;
use App\Domain\Reports\Http\Requests\CgaPersonRequest;
use App\Domain\Reports\Http\Requests\CgaResourceRequest;
use App\Domain\Reports\Http\Requests\PresentationRequest;
use App\Domain\Reports\Http\Resources\CgaPersonResource;
use App\Domain\Reports\Http\Resources\CgaResourceResource;
use App\Domain\Reports\Http\Resources\PresentationResource;
use App\Domain\Reports\Models\CgaPerson;
use App\Domain\Reports\Models\CgaResource;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/cga/permanent-profile : fiche permanente du CGA (/cga/gestion-interne, onglet « Fiche
 * permanente », F-RPT-01) — fiche technique et présentation, dirigeants, salariés et occasionnels,
 * moyens matériels par catégorie. Revue avant chaque génération du rapport trimestriel.
 */
class PermanentProfileController extends BaseApiController
{
    public function __construct(private readonly TenantContext $context) {}

    public function show(): JsonResponse
    {
        return $this->handle(function (): JsonResponse {
            Gate::authorize('view', CgaPerson::class);
            $cga = $this->context->organization()->loadMissing('regionalDirectorate');
            $resources = CgaResource::query()->get()->keyBy(static fn (CgaResource $resource): string => $resource->category->value);

            return response()->json(['data' => [
                'presentation' => new PresentationResource($cga),
                'people' => CgaPersonResource::collection(CgaPerson::query()->orderByDesc('active')->orderBy('kind')->orderBy('full_name')->get()),
                'resources' => array_map(static fn (CgaResourceCategory $category): array => [
                    'category' => $category->value,
                    'category_label' => $category->label(),
                    'entry' => $resources->has($category->value) ? new CgaResourceResource($resources->get($category->value)) : null,
                ], CgaResourceCategory::cases()),
                'can' => ['manage' => Gate::allows('manage', CgaPerson::class)],
            ]]);
        }, action: 'permanent_profile.show');
    }

    public function updatePresentation(PresentationRequest $request, UpdateCgaProfile $update): PresentationResource|JsonResponse
    {
        return $this->handle(function () use ($request, $update): PresentationResource {
            $cga = $update->execute($this->context->organization(), $request->presentation());
            $this->logSubject($cga);

            return new PresentationResource($cga->loadMissing('regionalDirectorate'));
        }, action: 'permanent_profile.presentation');
    }

    public function storePerson(CgaPersonRequest $request, SaveCgaPerson $save): JsonResponse
    {
        return $this->handle(function () use ($request, $save): JsonResponse {
            $person = $save->execute($this->context->organizationId(), $request->entry());
            $this->logSubject($person);

            return (new CgaPersonResource($person))->response()->setStatusCode(201);
        }, action: 'permanent_profile.people.store');
    }

    public function updatePerson(CgaPersonRequest $request, CgaPerson $cgaPerson, SaveCgaPerson $save): CgaPersonResource|JsonResponse
    {
        return $this->handle(fn (): CgaPersonResource => new CgaPersonResource(
            $save->execute($this->context->organizationId(), $request->entry(), $cgaPerson),
        ), action: 'permanent_profile.people.update');
    }

    public function saveResource(CgaResourceRequest $request, CgaResourceCategory $category, SaveCgaResource $save): CgaResourceResource|JsonResponse
    {
        return $this->handle(function () use ($request, $category, $save): CgaResourceResource {
            $resource = $save->execute($this->context->organizationId(), $category, $request->quantity(), $request->text('description'), $request->text('need'));
            $this->logSubject($resource);

            return new CgaResourceResource($resource);
        }, action: 'permanent_profile.resources.save');
    }
}
