<?php

namespace App\Domain\Obligations\Http\Requests;

use App\Domain\Obligations\Http\Requests\Concerns\ManagesMemberInstance;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/cga/members/{member}/obligation-instances/{obligationInstance}/member-declaration/reject :
 * rejet d'une déclaration de l'adhérent, motif obligatoire (RG-OBL-09, décision 0004).
 */
class RejectMemberDeclarationRequest extends FormRequest
{
    use ManagesMemberInstance;

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
