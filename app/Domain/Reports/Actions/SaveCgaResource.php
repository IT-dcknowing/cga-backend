<?php

namespace App\Domain\Reports\Actions;

use App\Domain\Reports\Enums\CgaResourceCategory;
use App\Domain\Reports\Models\CgaResource;
use Illuminate\Support\Facades\DB;

/**
 * Moyens matériels d'une catégorie de la fiche permanente (F-RPT-01) : une ligne par catégorie et par
 * centre, créée au premier enregistrement puis mise à jour (quantité, description, besoins).
 */
class SaveCgaResource
{
    public function execute(string $organizationId, CgaResourceCategory $category, int $quantity, ?string $description, ?string $need): CgaResource
    {
        return DB::transaction(function () use ($organizationId, $category, $quantity, $description, $need): CgaResource {
            $resource = CgaResource::query()->where('organization_id', $organizationId)->where('category', $category)->lockForUpdate()->first() ?? new CgaResource;
            $resource->organization_id = $organizationId;
            $resource->category = $category;
            $resource->quantity = $quantity;
            $resource->description = $description;
            $resource->need = $need;
            $resource->save();

            return $resource;
        });
    }
}
