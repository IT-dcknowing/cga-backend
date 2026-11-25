<?php

namespace App\Domain\Obligations\Http\Requests;

use App\Domain\Obligations\Enums\InstanceStatus;
use App\Domain\Obligations\Http\Requests\Concerns\ManagesMemberInstance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /api/v1/cga/members/{member}/obligation-instances/{obligationInstance}/diligence : avancement d'une
 * diligence — non établie, en cours, établie (F-OBL-04).
 */
class DiligenceStatusRequest extends FormRequest
{
    use ManagesMemberInstance;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(array_map(
                static fn (InstanceStatus $status): string => $status->value,
                [InstanceStatus::NotEstablished, InstanceStatus::InProgress, InstanceStatus::Established],
            ))],
        ];
    }

    public function status(): InstanceStatus
    {
        return InstanceStatus::from((string) $this->validated('status'));
    }
}
