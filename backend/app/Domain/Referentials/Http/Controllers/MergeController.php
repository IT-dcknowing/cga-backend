<?php

namespace App\Domain\Referentials\Http\Controllers;

use App\Domain\Referentials\Actions\MergeReferential;
use App\Domain\Referentials\Contracts\Referential;
use App\Domain\Referentials\Http\Requests\MergeRequest;
use App\Domain\Referentials\Http\Resources\CommuneResource;
use App\Domain\Referentials\Http\Resources\RegionalDirectorateResource;
use App\Domain\Referentials\Http\Resources\SectorResource;
use App\Domain\Referentials\Http\Resources\TaxOfficeResource;
use App\Domain\Referentials\Models\Commune;
use App\Domain\Referentials\Models\RegionalDirectorate;
use App\Domain\Referentials\Models\Sector;
use App\Domain\Referentials\Models\TaxOffice;
use App\Support\Http\BaseApiController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * POST /api/v1/referentials/{référentiel}/{valeur}/merge : fusion d'une valeur dans une autre du même
 * référentiel (RG-DATA-05), droit referentials.manage. Répond la valeur cible.
 */
class MergeController extends BaseApiController
{
    public function regionalDirectorate(MergeRequest $request, RegionalDirectorate $regionalDirectorate, MergeReferential $merge): JsonResource|JsonResponse
    {
        return $this->handle(fn (): JsonResource => new RegionalDirectorateResource($this->merge($request, $regionalDirectorate, $merge)), action: 'referentials.regional-directorates.merge');
    }

    public function taxOffice(MergeRequest $request, TaxOffice $taxOffice, MergeReferential $merge): JsonResource|JsonResponse
    {
        return $this->handle(fn (): JsonResource => new TaxOfficeResource($this->merge($request, $taxOffice, $merge)->load('regionalDirectorate')), action: 'referentials.tax-offices.merge');
    }

    public function commune(MergeRequest $request, Commune $commune, MergeReferential $merge): JsonResource|JsonResponse
    {
        return $this->handle(fn (): JsonResource => new CommuneResource($this->merge($request, $commune, $merge)), action: 'referentials.communes.merge');
    }

    public function sector(MergeRequest $request, Sector $sector, MergeReferential $merge): JsonResource|JsonResponse
    {
        return $this->handle(fn (): JsonResource => new SectorResource($this->merge($request, $sector, $merge)), action: 'referentials.sectors.merge');
    }

    private function merge(MergeRequest $request, Model&Referential $source, MergeReferential $merge): Model
    {
        $target = $source->newQuery()->findOrFail($request->targetId());

        return $merge->execute($source, $target);
    }
}
