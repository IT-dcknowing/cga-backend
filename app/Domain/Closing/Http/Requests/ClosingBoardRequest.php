<?php

namespace App\Domain\Closing\Http\Requests;

use App\Domain\Closing\Models\ClosingCampaign;
use App\Domain\Members\Enums\Portfolio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/cga/closing-campaigns/{closingCampaign} : campagne et tableau de suivi (RG-OBL-11),
 * nombre de dossiers par étape et retards, filtrés par portefeuille et par agent.
 */
class ClosingBoardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('view', ClosingCampaign::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'portfolio' => ['nullable', Rule::enum(Portfolio::class)],
            'assigned_to' => ['nullable', 'string', 'ulid'],
        ];
    }

    public function portfolio(): ?Portfolio
    {
        $portfolio = $this->validated('portfolio');

        return is_string($portfolio) ? Portfolio::from($portfolio) : null;
    }

    public function assignedTo(): ?string
    {
        $agent = $this->validated('assigned_to');

        return is_string($agent) ? $agent : null;
    }
}
