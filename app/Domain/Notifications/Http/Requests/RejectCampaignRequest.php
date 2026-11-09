<?php

namespace App\Domain\Notifications\Http\Requests;

use App\Domain\Notifications\Models\MessageCampaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/v1/cga/campaigns/{campaign}/reject : refus d'un envoi groupé par le gérant (RG-COM-01), avec
 * son motif.
 */
class RejectCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('approve', MessageCampaign::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:1000']];
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }
}
