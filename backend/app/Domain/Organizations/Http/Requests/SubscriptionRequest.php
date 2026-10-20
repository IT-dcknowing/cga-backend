<?php

namespace App\Domain\Organizations\Http\Requests;

use App\Domain\Organizations\Enums\SubscriptionStatus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/network/organizations/{organization}/subscription : nouvel état d'abonnement du CGA
 * (F-ORG-05), motif facultatif (à défaut, l'historique note l'ancien et le nouvel état).
 */
class SubscriptionRequest extends OrganizationRequest
{
    public function authorize(): bool
    {
        return Gate::allows('changeSubscription', $this->organization());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(SubscriptionStatus::class)],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function status(): SubscriptionStatus
    {
        return SubscriptionStatus::from($this->validated('status'));
    }

    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return is_string($reason) ? $reason : null;
    }
}
