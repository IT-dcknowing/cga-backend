<?php

namespace App\Domain\Obligations\Http\Requests;

use App\Domain\Members\Models\Member;
use App\Domain\Obligations\Models\MemberObligation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/v1/cga/members/{member}/obligations/review : obligations proposées à confirmer, obligations
 * à retirer (F-OBL-02, RG-OBL-02) ; au moins l'une des deux listes, sans identifiant dans les deux.
 */
class ReviewMemberObligationsRequest extends FormRequest
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
            'confirm' => ['array', 'max:200', 'required_without:remove'],
            'confirm.*' => ['string', 'ulid', 'distinct'],
            'remove' => ['array', 'max:200', 'required_without:confirm'],
            'remove.*' => ['string', 'ulid', 'distinct'],
        ];
    }

    /**
     * @return list<string>
     */
    public function confirmed(): array
    {
        return array_values(array_map(strval(...), (array) ($this->validated('confirm') ?? [])));
    }

    /**
     * @return list<string>
     */
    public function removed(): array
    {
        return array_values(array_map(strval(...), (array) ($this->validated('remove') ?? [])));
    }
}
