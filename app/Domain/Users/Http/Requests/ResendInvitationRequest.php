<?php

namespace App\Domain\Users\Http\Requests;

use App\Domain\Auth\Enums\InvitationChannel;
use App\Domain\Users\Models\User;
use App\Domain\Users\Support\AgentSpace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/{cga|network}/users/{agent}/invitation : renvoi du lien d'activation, par SMS (par
 * défaut), e-mail ou les deux (RG-ACC-03).
 */
class ResendInvitationRequest extends FormRequest
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
            'channel' => ['nullable', Rule::enum(InvitationChannel::class)],
        ];
    }

    public function channel(): InvitationChannel
    {
        return InvitationChannel::tryFrom((string) $this->validated('channel')) ?? InvitationChannel::Sms;
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
