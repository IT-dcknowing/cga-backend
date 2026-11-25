<?php

namespace App\Domain\Organizations\Http\Requests;

use Illuminate\Support\Facades\Gate;

/**
 * POST /api/v1/network/organizations/{organization}/suspend et …/reactivate : suspension ou
 * réactivation du CGA par la Présidente, motif obligatoire (F-ORG-06).
 */
class StatusReasonRequest extends OrganizationRequest
{
    public function authorize(): bool
    {
        return Gate::allows('suspend', $this->organization());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    public function reason(): string
    {
        return (string) $this->validated('reason');
    }
}
