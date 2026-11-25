<?php

namespace App\Domain\Users\Http\Requests;

use App\Domain\Auth\Enums\InvitationChannel;
use App\Domain\Users\Data\AgentIdentity;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\User;
use App\Domain\Users\Support\AgentSpace;
use App\Domain\Users\Support\TeamRoles;
use App\Support\Phones\PhoneNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use UnexpectedValueException;

/**
 * POST /api/v1/{cga|network}/users : invitation d'un agent (F-ORG-09, F-ORG-10) — nom, prénom,
 * téléphone, e-mail, un ou plusieurs rôles d'agent de l'espace, canal du lien (SMS par défaut).
 */
class InviteAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', [User::class, AgentSpace::of($this)]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(PhoneNormalizer $phones): array
    {
        return [
            ...self::identityRules($phones),
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'distinct', Rule::in(array_map(static fn (Role $role): string => $role->value, TeamRoles::assignable(AgentSpace::of($this))))],
        ];
    }

    /**
     * Identité et canal, communs à l'invitation d'un agent et à celle du gérant d'un CGA.
     *
     * @return array<string, list<mixed>>
     */
    public static function identityRules(PhoneNormalizer $phones): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:30', $phones->rule()],
            'email' => ['nullable', 'required_if:channel,email,sms_email', 'string', 'email', 'max:255'],
            'channel' => ['nullable', Rule::enum(InvitationChannel::class)],
        ];
    }

    public function identity(): AgentIdentity
    {
        return AgentIdentity::fromValidated($this->validated());
    }

    public function channel(): InvitationChannel
    {
        return InvitationChannel::tryFrom((string) $this->validated('channel')) ?? InvitationChannel::Sms;
    }

    /**
     * @return non-empty-list<Role>
     */
    public function roles(): array
    {
        $roles = array_values(array_map(static fn (mixed $role): Role => Role::from((string) $role), (array) $this->validated('roles')));

        return $roles === [] ? throw new UnexpectedValueException('Rôles non validés.') : $roles;
    }
}
