<?php

namespace App\Domain\Members\Http\Requests;

use App\Domain\Auth\Enums\InvitationChannel;
use App\Domain\Members\Http\Requests\Concerns\RouteMember;
use App\Domain\Users\Data\AgentIdentity;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Http\Requests\InviteAgentRequest;
use App\Support\Phones\PhoneNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/cga/members/{member}/accesses : « Inviter » un accès en ligne de l'adhérent (F-ADH-09)
 * — nom, téléphone, e-mail facultatif, type d'accès (principal ou secondaire), canal.
 */
class InviteMemberAccessRequest extends FormRequest
{
    use RouteMember;

    public function authorize(): bool
    {
        return Gate::allows('manageAccesses', $this->member());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(PhoneNormalizer $phones): array
    {
        return [
            ...InviteAgentRequest::identityRules($phones),
            'role' => ['required', Rule::in([Role::MemberOwner->value, Role::MemberUser->value])],
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

    public function role(): Role
    {
        return Role::from((string) $this->validated('role'));
    }
}
