<?php

namespace App\Domain\Notifications\Http\Resources;

use App\Domain\Notifications\Models\MessageCampaign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Campagne de messages groupés (F-COM-03) : objet, texte, canaux, filtre des destinataires, nombre de
 * destinataires calculé par le serveur, statut, motif de refus, dates ; en liste, la délivrance par statut
 * (envoyé, échec…) tirée du journal des messages.
 *
 * @property MessageCampaign $resource
 */
class MessageCampaignResource extends JsonResource
{
    /** @var array<string, int> statut => envois */
    private array $delivery = [];

    /**
     * @param  array<string, int>  $delivery
     */
    public function withDelivery(array $delivery): self
    {
        $this->delivery = $delivery;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $campaign = $this->resource;

        return [
            'id' => $campaign->id,
            'title' => $campaign->title,
            'body' => $campaign->body,
            'channels' => $campaign->channels,
            'filter' => $campaign->recipient_filter,
            'recipients_count' => $campaign->recipients_count,
            'status' => $campaign->status->value,
            'status_label' => $campaign->status->label(),
            'rejection_reason' => $campaign->rejection_reason,
            'approved_at' => $campaign->approved_at?->toIso8601ZuluString(),
            'sent_at' => $campaign->sent_at?->toIso8601ZuluString(),
            'created_at' => $campaign->created_at->toIso8601ZuluString(),
            'delivery' => (object) $this->delivery,
        ];
    }
}
