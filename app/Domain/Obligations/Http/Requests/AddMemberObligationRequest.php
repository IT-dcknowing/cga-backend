<?php

namespace App\Domain\Obligations\Http\Requests;

use App\Domain\Members\Models\Member;
use App\Domain\Obligations\Models\MemberObligation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/v1/cga/members/{member}/obligations : ajout d'une obligation au dossier (F-OBL-02).
 */
class AddMemberObligationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $member = $this->route('member');

        return $member instanceof Member && Gate::allows('manage', [MemberObligation::class, $member]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'obligation_type_id' => ['required', 'string', 'ulid'],
        ];
    }
}
