<?php

namespace App\Domain\Obligations\Http\Resources;

use App\Domain\Obligations\Models\ObligationInstance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Adhérent concerné par une ligne du planning (panneau latéral, F-OBL-06) : son échéance, son statut,
 * sa déclaration à confirmer, et le lien vers son dossier.
 *
 * @property ObligationInstance $resource
 */
class PlanningMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $instance = $this->resource;

        return [
            'instance_id' => $instance->id,
            'status' => $instance->status->value,
            'status_label' => $instance->status->label(),
            'member_declared_status' => $instance->member_declared_status?->value,
            'amount_due' => $instance->amount_due,
            'balance' => $instance->balance(),
            'member' => [
                'id' => $instance->member->id,
                'company_name' => $instance->member->company_name,
                'membership_number' => $instance->member->membership_number,
                'client_number' => $instance->member->client_number,
            ],
        ];
    }
}
