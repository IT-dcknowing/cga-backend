<?php

namespace App\Domain\Users\Http\Requests;

use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\RolePermission;
use App\Domain\Users\Permissions\PermissionLevel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * PUT /api/v1/{network|cga}/roles/{role}/permissions : liste complète des droits accordés au rôle, et
 * GET …/impact : même liste, pour l'aperçu avant d'enregistrer. Codes verrouillés ou hors catalogue
 * ignorés par le serveur (02-TRD §6.2).
 */
class RolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', [RolePermission::class, PermissionLevel::of($this), $this->role()]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'permissions' => [$this->isMethod('GET') ? 'sometimes' : 'present', 'array'],
            'permissions.*' => ['string', 'max:80'],
        ];
    }

    /**
     * @return list<string>
     */
    public function granted(): array
    {
        return array_values(array_map(strval(...), (array) ($this->validated('permissions') ?? [])));
    }

    /**
     * @throws AuthorizationException route sans rôle
     */
    public function role(): Role
    {
        $role = $this->route('role');

        return $role instanceof Role ? $role : throw new AuthorizationException;
    }
}
