<?php

namespace App\Domain\Users\Http\Requests;

use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\User;
use App\Domain\Users\Support\AgentSpace;
use App\Domain\Users\Support\TeamRoles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use UnexpectedValueException;

/**
 * PUT /api/v1/{cga|network}/users/{agent}/roles : liste complète des rôles d'agent (F-ORG-09).
 */
class AgentRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('update', [$this->agent(), AgentSpace::of($this)]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'distinct', Rule::in(array_map(static fn (Role $role): string => $role->value, TeamRoles::assignable(AgentSpace::of($this))))],
        ];
    }

    /**
     * @return non-empty-list<Role>
     */
    public function roles(): array
    {
        $roles = array_values(array_map(static fn (mixed $role): Role => Role::from((string) $role), (array) $this->validated('roles')));

        return $roles === [] ? throw new UnexpectedValueException('Rôles non validés.') : $roles;
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
