<?php

namespace App\Domain\Closing\Http\Resources;

use App\Domain\Closing\Enums\ClosingCampaignStatus;
use App\Domain\Closing\Enums\ClosingStep;
use App\Domain\Closing\Models\ClosingCampaignItem;
use App\Domain\Closing\Support\ClosingReminders;
use App\Support\Files\StorageService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Avancement d'un dossier dans la campagne de clôture (F-OBL-08) : étapes (échéance, date faite,
 * retard), étape en cours, livrable, dépôt (référence, accusé), agent, actions permises. Dans l'espace
 * de l'adhérent : ses étapes et son dépôt seulement.
 *
 * @property ClosingCampaignItem $resource
 */
class ClosingItemResource extends JsonResource
{
    private bool $manages = false;

    private bool $member = false;

    public function managedBy(bool $manages): self
    {
        $this->manages = $manages;

        return $this;
    }

    public function forMember(): self
    {
        $this->member = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = $this->resource;
        $campaign = $item->campaign;
        $today = CarbonImmutable::today();
        $late = ClosingReminders::lateStep($campaign, $item, $today);
        $open = $campaign->status === ClosingCampaignStatus::Open;

        $data = [
            'id' => $item->id,
            'campaign' => ['id' => $campaign->id, 'fiscal_year' => $campaign->fiscal_year, 'status' => $campaign->status->value],
            'deliverable' => $item->deliverable->value,
            'deliverable_label' => $item->deliverable->label(),
            'steps' => array_map(static fn (ClosingStep $step): array => [
                'code' => $step->value,
                'label' => $step->label(),
                'due' => $campaign->due($step)->toDateString(),
                'done_on' => $item->doneOn($step)?->toDateString(),
                'late' => $step === $late,
            ], ClosingStep::cases()),
            'next_step' => $item->nextStep()?->value,
            'late_step' => $late?->value,
            'filing_reference' => $item->filing_reference,
            'filing_receipt' => $this->whenLoaded('filingFile', fn (): ?array => $item->filingFile === null ? null : [
                'id' => $item->filingFile->id,
                'name' => $item->filingFile->original_name,
                'url' => app(StorageService::class)->temporaryUrl($item->filingFile),
            ]),
        ];

        if ($this->member) {
            return $data;
        }

        return [
            ...$data,
            'member' => $this->whenLoaded('member', fn (): array => [
                'id' => $item->member->id,
                'company_name' => $item->member->company_name,
                'membership_number' => $item->member->membership_number,
                'client_number' => $item->member->client_number,
            ]),
            'portfolio' => $item->portfolio->value,
            'portfolio_label' => $item->portfolio->label(),
            'assigned_to' => $this->whenLoaded('assignee', fn (): ?array => $item->assignee === null ? null : ['id' => $item->assignee->id, 'name' => $item->assignee->fullName()]),
            'can' => [
                'mark_step' => $this->manages && $open && $item->nextStep() !== null,
                'update' => $this->manages && $open,
            ],
        ];
    }
}
