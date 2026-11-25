<?php

namespace App\Domain\Notifications\Http\Requests;

use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Notifications\Models\MessageCampaign;
use App\Support\Config\SettingsService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Destinataires et canaux d'un envoi groupé (F-COM-03, 03-APPFLOW §4.3 étapes 1 et 2) : filtre par
 * portefeuille, régimes, secteurs, honoraires impayés et dossiers choisis (cherchés dans le centre),
 * canaux. Sert à l'aperçu (POST /api/v1/cga/campaigns/preview : compteur en direct, quota SMS) et à la
 * préparation de la campagne. Le portefeuille Cabinet ou les deux exigent members.cabinet.view.
 */
class CampaignAudienceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('send', MessageCampaign::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(TenantContext $context, SettingsService $settings): array
    {
        $portfolios = $context->allows('members.cabinet.view') ? ['cga', 'cabinet', 'all'] : ['cga'];
        $max = (int) $settings->get('pagination.max');

        return [
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['string', 'distinct', Rule::in(['platform', 'email', 'sms', 'whatsapp'])],
            'filter' => ['present', 'array:portfolio,regimes,sector_ids,unpaid_fees,member_ids'],
            'filter.portfolio' => ['nullable', Rule::in($portfolios)],
            'filter.regimes' => ['nullable', 'array'],
            'filter.regimes.*' => [Rule::enum(TaxRegime::class)],
            'filter.sector_ids' => ['nullable', 'array', 'max:'.$max],
            'filter.sector_ids.*' => ['string', 'size:26', 'distinct', Rule::exists('sectors', 'id')],
            'filter.unpaid_fees' => ['nullable', 'boolean'],
            'filter.member_ids' => ['nullable', 'array', 'max:'.$max],
            'filter.member_ids.*' => ['string', 'size:26', 'distinct', Rule::exists('members', 'id')->where('organization_id', $context->organizationId())],
        ];
    }

    /**
     * @return list<string>
     */
    public function channels(): array
    {
        return array_values(array_map('strval', (array) $this->validated('channels')));
    }

    /**
     * @return array{portfolio: string, regimes: list<string>, sector_ids: list<string>, unpaid_fees: bool, member_ids: list<string>}
     */
    public function filter(): array
    {
        $filter = (array) $this->validated('filter');

        return [
            'portfolio' => (string) ($filter['portfolio'] ?? 'cga'),
            'regimes' => array_values(array_map('strval', (array) ($filter['regimes'] ?? []))),
            'sector_ids' => array_values(array_map('strval', (array) ($filter['sector_ids'] ?? []))),
            'unpaid_fees' => (bool) ($filter['unpaid_fees'] ?? false),
            'member_ids' => array_values(array_map('strval', (array) ($filter['member_ids'] ?? []))),
        ];
    }
}
