<?php

namespace App\Domain\Members\Http\Requests;

use App\Domain\Auth\Enums\InvitationChannel;
use App\Domain\Members\Http\Requests\Concerns\RouteMember;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/cga/members/{member}/accesses/{access}/resend|disable|enable : renvoi de l'invitation
 * (canal au choix), désactivation, réactivation d'un accès en ligne (F-ADH-09), par le gérant.
 */
class MemberAccessRequest extends FormRequest
{
    use RouteMember;

    public function authorize(): bool
    {
        return Gate::allows('manageAccesses', $this->member());
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
}
