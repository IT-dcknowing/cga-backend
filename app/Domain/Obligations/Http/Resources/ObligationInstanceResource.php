<?php

namespace App\Domain\Obligations\Http\Resources;

use App\Domain\Obligations\Enums\InstanceKind;
use App\Domain\Obligations\Enums\InstanceStatus;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Domain\Obligations\Support\ObligationSummary;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ligne d'échéance d'un bloc Impôts, CNPS, CMU ou Autres administrations (F-OBL-04) : période, libellé,
 * échéance, plateforme, montant dû (avant et après abattement pour la TEE), payé, reste, statut,
 * déclaration de l'adhérent à confirmer (RG-OBL-09), paiements, actions permises.
 *
 * @property ObligationInstance $resource
 */
class ObligationInstanceResource extends JsonResource
{
    private bool $manages = false;

    /** Le lecteur gère-t-il les obligations du dossier (boutons de la ligne) ? */
    public function managedBy(bool $manages): self
    {
        $this->manages = $manages;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $instance = $this->resource;
        $live = $this->manages && $instance->status !== InstanceStatus::Cancelled;

        return [
            'id' => $instance->id,
            'label' => $instance->label,
            'category' => $instance->category->value,
            'category_label' => $instance->category->label(),
            'kind' => $instance->kind->value,
            'kind_label' => $instance->kind->label(),
            'authority_name' => $instance->authority_name,
            'period_label' => $instance->period_label,
            'period_start' => $instance->period_start->toDateString(),
            'period_end' => $instance->period_end->toDateString(),
            'due_date' => $instance->due_date->toDateString(),
            'overdue' => in_array($instance->status, ObligationSummary::open(), true) && $instance->due_date->lessThan(CarbonImmutable::today()),
            'platform' => $instance->platform,
            'tax_nature' => $instance->tax_nature?->value,
            'tax_nature_label' => $instance->tax_nature?->label(),
            'amount_gross' => $instance->amount_gross,
            'amount_due' => $instance->amount_due,
            'amount_paid' => $instance->amount_paid,
            'balance' => $instance->kind === InstanceKind::Payment ? $instance->balance() : null,
            'status' => $instance->status->value,
            'status_label' => $instance->status->label(),
            'member_declared_status' => $instance->member_declared_status?->value,
            'member_declared_status_label' => $instance->member_declared_status?->label(),
            'member_declared_at' => $instance->member_declared_at?->toIso8601ZuluString(),
            'parent_instance_id' => $instance->parent_instance_id,
            'done_at' => $instance->done_at?->toDateString(),
            'payments' => ObligationPaymentResource::collection($this->whenLoaded('payments')),
            'can' => [
                'mark_done' => $live && $instance->kind === InstanceKind::Declaration && $instance->status === InstanceStatus::Todo,
                'set_amount' => $live && $instance->kind === InstanceKind::Payment,
                'record_payment' => $live && $instance->kind === InstanceKind::Payment && $instance->amount_due !== null && ($instance->balance() ?? 0) > 0,
                'set_diligence' => $live && $instance->kind === InstanceKind::Diligence,
            ],
        ];
    }
}
