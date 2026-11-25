<?php

namespace App\Domain\Closing\Http\Requests;

use App\Domain\Closing\Enums\ClosingDeliverable;
use App\Domain\Closing\Models\ClosingCampaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * PUT /api/v1/cga/closing-campaigns/{closingCampaign}/items/{item} : livrable du dossier (SMT ou états
 * financiers, décision 0006) et agent qui le suit (aucun : null).
 */
class UpdateClosingItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', ClosingCampaign::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'deliverable' => ['required', Rule::enum(ClosingDeliverable::class)],
            'assigned_to' => ['present', 'nullable', 'string', 'ulid'],
        ];
    }

    public function deliverable(): ClosingDeliverable
    {
        return ClosingDeliverable::from((string) $this->validated('deliverable'));
    }

    public function assignedTo(): ?string
    {
        $agent = $this->validated('assigned_to');

        return is_string($agent) ? $agent : null;
    }
}
