<?php

namespace App\Domain\Concerns\Http\Resources;

use App\Domain\Concerns\Enums\ConcernLevel;
use App\Domain\Concerns\Enums\ConcernSide;
use App\Domain\Concerns\Models\Concern;
use App\Domain\Concerns\Models\ConcernMessage;
use App\Support\Config\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Préoccupation (PRD M10) vue d'un espace : en-tête (objet, catégorie, statut, délai écoulé et délai visé,
 * RG-PRE-01), fil quand il est chargé, actions permises. Pour qui la traite : demande en attente de réponse
 * et « sans réponse depuis n jours » (RG-PRE-02). Le dossier n'est donné qu'au CGA ; le CNCGA ne reçoit que
 * le centre émetteur (RG-SEC-01). Le demandeur ne voit ni les notes internes ni la demande liée au CNCGA,
 * seulement qu'elle a été transmise.
 *
 * @property Concern $resource
 */
class ConcernResource extends JsonResource
{
    public const SPACE_MEMBER = 'member';

    public const SPACE_CGA = 'cga';

    public const SPACE_NETWORK = 'network';

    private string $space = self::SPACE_CGA;

    private ConcernSide $side = ConcernSide::Requester;

    /** @var array<string, bool>|null */
    private ?array $abilities = null;

    public function viewedFrom(string $space, ConcernSide $side): self
    {
        $this->space = $space;
        $this->side = $side;

        return $this;
    }

    /**
     * @param  array<string, bool>  $abilities
     */
    public function withAbilities(array $abilities): self
    {
        $this->abilities = $abilities;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $concern = $this->resource;
        $now = CarbonImmutable::now();
        $elapsed = (int) $concern->created_at->diffInDays($concern->resolved_at ?? $now);
        $answeredOn = $concern->first_response_at ?? $now;

        $data = [
            'id' => $concern->id,
            'level' => $concern->level->value,
            'category' => $concern->category->value,
            'category_label' => $concern->category->label(),
            'subject' => $concern->subject,
            'status' => $concern->status->value,
            'status_label' => $concern->status->label(),
            'rejection_reason' => $concern->rejection_reason,
            'created_at' => $concern->created_at->toIso8601String(),
            'last_activity_at' => $concern->last_activity_at->toIso8601String(),
            'first_response_at' => $concern->first_response_at?->toIso8601String(),
            'resolved_at' => $concern->resolved_at?->toIso8601String(),
            'elapsed_days' => $elapsed,
            'target_response_days' => $concern->target_response_days,
            'response_late' => $concern->created_at->addDays($concern->target_response_days)->lessThan($answeredOn),
            'reopen_count' => $concern->reopen_count,
            'transferred' => $concern->transferred_to_concern_id !== null,
            'author' => $this->whenLoaded('author', fn (): array => ['name' => $concern->author->fullName()]),
        ];

        if ($this->side === ConcernSide::Handler) {
            $data['awaiting_reply'] = $concern->awaiting_reply_since !== null && ! $concern->status->isClosed();
            $data['awaiting_since'] = $concern->awaiting_reply_since?->toIso8601String();
            $data['stale'] = $concern->level === ConcernLevel::MemberToCga && $data['awaiting_reply']
                && $concern->awaiting_reply_since?->lessThanOrEqualTo($now->subDays((int) app(SettingsService::class)->get('concerns.stale_days', $concern->organization_id))) === true;
        }

        if ($this->space === self::SPACE_CGA) {
            $data['member'] = $this->whenLoaded('member', fn (): ?array => $concern->member === null ? null : [
                'id' => $concern->member->id,
                'company_name' => $concern->member->company_name,
                'membership_number' => $concern->member->membership_number,
                'portfolio' => $concern->member->portfolio->value,
            ]);
            $data['transfer'] = $this->whenLoaded('transferredTo', fn (): ?array => $concern->transferredTo === null ? null : [
                'id' => $concern->transferredTo->id,
                'status' => $concern->transferredTo->status->value,
                'status_label' => $concern->transferredTo->status->label(),
            ]);
        }

        if ($this->space === self::SPACE_NETWORK) {
            $data['organization'] = $this->whenLoaded('organization', fn (): array => ['id' => $concern->organization->id, 'name' => $concern->organization->name]);
            $data['from_transfer'] = $concern->transferred_from_concern_id !== null;
        }

        if ($concern->relationLoaded('messages')) {
            $data['messages'] = $concern->messages->map(fn (ConcernMessage $message): array => (new ConcernMessageResource($message))->forHandler($this->side === ConcernSide::Handler)->toArray($request))->all();
        }

        if ($this->abilities !== null) {
            $data['can'] = $this->abilities;
        }

        return $data;
    }
}
