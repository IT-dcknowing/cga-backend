<?php

namespace App\Domain\Imports\Http\Resources;

use App\Domain\Imports\Models\ImportRow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sort d'une ligne du fichier importé (RG-IMP-02) : numéro de ligne Excel, statut, motifs ou champs
 * manquants, objet créé.
 *
 * @property ImportRow $resource
 */
class ImportRowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $row = $this->resource;

        return [
            'row_number' => $row->row_number,
            'status' => $row->status->value,
            'status_label' => $row->status->label(),
            'messages' => $row->messages,
            'created_entity_id' => $row->created_entity_id,
        ];
    }
}
