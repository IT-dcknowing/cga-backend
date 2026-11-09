<?php

namespace App\Domain\Obligations\Http\Controllers;

use App\Domain\Obligations\Actions\ApplyObligationCatalogVersion;
use App\Domain\Obligations\Actions\LoadObligationCatalog;
use App\Domain\Obligations\Actions\ValidateObligationCatalogVersion;
use App\Domain\Obligations\Http\Requests\ObligationCatalogFileRequest;
use App\Domain\Obligations\Http\Resources\CatalogImpactResource;
use App\Domain\Obligations\Http\Resources\CatalogVersionResource;
use App\Domain\Obligations\Models\ObligationType;
use App\Domain\Obligations\Support\CatalogFile;
use App\Domain\Obligations\Support\CatalogVersionPlanner;
use App\Domain\Referentials\Enums\DataStatus;
use App\Support\Files\StorageService;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/network/obligation-catalog : catalogue national des obligations (F-OBL-01, RG-DATA-04,
 * onglet « Catalogue des obligations » de /cncga/parametres) — versions chargées, aperçu des effets
 * d'une nouvelle version sur les dossiers (nombres seuls), application, « Marquer comme validé ».
 */
class ObligationCatalogController extends BaseApiController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly LoadObligationCatalog $load,
    ) {}

    public function index(): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function (): AnonymousResourceCollection {
            Gate::authorize('manageCatalog', ObligationType::class);

            $versions = ObligationType::query()->whereNull('organization_id')->get()
                ->groupBy('catalog_version')
                ->map(static fn (Collection $types, string $version): array => [
                    'version' => $version,
                    'count' => $types->count(),
                    'provisional_count' => $types->where('data_status', DataStatus::Provisional)->count(),
                    'in_force' => $types->contains('active', true),
                    'validated_at' => $types->max('validated_at')?->toIso8601ZuluString(),
                    'loaded_at' => $types->min('created_at')?->toIso8601ZuluString(),
                ])
                ->sortByDesc('loaded_at')
                ->values();

            return CatalogVersionResource::collection($versions);
        }, action: 'obligation_catalog.index');
    }

    public function preview(ObligationCatalogFileRequest $request, StorageService $storage, CatalogVersionPlanner $planner): CatalogImpactResource|JsonResponse
    {
        return $this->handle(function () use ($request, $storage, $planner): CatalogImpactResource {
            $validated = $this->load->validate($request->catalog());
            $file = $storage->storeUpload($request->upload(), ObligationCatalogFileRequest::FILE_TYPE, $this->context->organizationId(), $this->currentUser()->id);
            $plan = Tenancy::bypass(
                fn (): array => $planner->plan($validated['version'], $this->load->types($validated), CarbonImmutable::today()),
                'Aperçu d\'une nouvelle version du catalogue des obligations : effets sur les dossiers de tous les centres, en nombres (RG-DATA-04).',
            );

            return new CatalogImpactResource(['file_id' => $file->id, ...$plan]);
        }, action: 'obligation_catalog.preview');
    }

    public function apply(ObligationCatalogFileRequest $request, StorageService $storage, ApplyObligationCatalogVersion $apply): CatalogImpactResource|JsonResponse
    {
        return $this->handle(function () use ($request, $storage, $apply): CatalogImpactResource {
            $file = $request->storedFile($this->context);
            $summary = $apply->execute(CatalogFile::decode($storage->contents($file), 'file_id'), CarbonImmutable::today());
            $this->logSubject($file);

            return new CatalogImpactResource($summary);
        }, action: 'obligation_catalog.apply');
    }

    public function validateVersion(string $version, ValidateObligationCatalogVersion $validate): JsonResponse
    {
        return $this->handle(function () use ($version, $validate): JsonResponse {
            Gate::authorize('manageCatalog', ObligationType::class);

            return (new CatalogImpactResource(['version' => $version, 'validated' => $validate->execute($version, CarbonImmutable::now())]))->response();
        }, action: 'obligation_catalog.validate');
    }
}
