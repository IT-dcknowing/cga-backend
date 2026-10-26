<?php

namespace App\Domain\Users\Http\Requests;

use App\Domain\Auth\Enums\InvitationChannel;
use App\Domain\Organizations\Http\Requests\OrganizationRequest;
use App\Domain\Users\Data\AgentIdentity;
use App\Support\Phones\PhoneNormalizer;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/v1/network/organizations/{organization}/manager : « Ouvrir l'accès » du CGA (F-ORG-02),
 * invitation de son gérant — nom, prénom, téléphone, e-mail, canal du lien.
 */
class InviteCgaManagerRequest extends OrganizationRequest
{
    public function authorize(): bool
    {
        return Gate::allows('inviteManager', $this->organization());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(PhoneNormalizer $phones): array
    {
        return InviteAgentRequest::identityRules($phones);
    }

    public function identity(): AgentIdentity
    {
        return AgentIdentity::fromValidated($this->validated());
    }

    public function channel(): InvitationChannel
    {
        return InvitationChannel::tryFrom((string) $this->validated('channel')) ?? InvitationChannel::Sms;
    }
}
