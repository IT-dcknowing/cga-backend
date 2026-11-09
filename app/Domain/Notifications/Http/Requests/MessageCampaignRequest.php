<?php

namespace App\Domain\Notifications\Http\Requests;

use App\Support\Config\SettingsService;
use App\Support\Tenancy\TenantContext;

/**
 * POST /api/v1/cga/campaigns : envoi groupé (F-COM-03) — objet, message (libre ou modèle), et les
 * destinataires et canaux de CampaignAudienceRequest.
 */
class MessageCampaignRequest extends CampaignAudienceRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(TenantContext $context, SettingsService $settings): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:2000'],
            ...parent::rules($context, $settings),
        ];
    }

    public function title(): string
    {
        return trim((string) $this->validated('title'));
    }

    public function body(): string
    {
        return trim((string) $this->validated('body'));
    }
}
