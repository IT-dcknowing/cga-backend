<?php

namespace App\Domain\Users\Http\Requests;

use App\Domain\Users\Http\Controllers\UserPermissionController;
use App\Domain\Users\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * PUT /api/v1/cga/users/{agent}/permissions et /api/v1/platform/network-users/{agent}/permissions :
 * liste des droits que l'agent doit avoir (RG-ROLE-07), motif facultatif. Codes verrouillés ou hors de
 * ses rôles ignorés.
 */
class UserPermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('adjustPermissions', [$this->agent(), UserPermissionController::spaceOf($this)]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'max:80'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return list<string>
     */
    public function granted(): array
    {
        return array_values(array_map(strval(...), (array) $this->validated('permissions')));
    }

    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return is_string($reason) ? $reason : null;
    }

    /**
     * @throws AuthorizationException route sans agent
     */
    public function agent(): User
    {
        $agent = $this->route('agent');

        return $agent instanceof User ? $agent : throw new AuthorizationException;
    }
}
