<?php

namespace App\Domain\Members\Http\Requests;

use App\Domain\Members\Http\Requests\Concerns\RouteMember;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/v1/cga/members/{member}/validate : « Valider l'adhésion » (F-ADH-02), avec, au portefeuille
 * CGA, la confirmation sur l'honneur de l'absence d'adhésion dans un autre CGA (RG-ADH-05).
 */
class ValidateMembershipRequest extends FormRequest
{
    use RouteMember;

    public function authorize(): bool
    {
        return Gate::allows('validate', $this->member());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'exclusivity_confirmed' => ['nullable', 'boolean'],
        ];
    }

    public function exclusivityConfirmed(): bool
    {
        return (bool) $this->validated('exclusivity_confirmed');
    }
}
