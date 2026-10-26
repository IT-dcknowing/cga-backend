<?php

namespace App\Domain\Documents\Http\Requests;

use App\Domain\Documents\Enums\AttestationBlockTrigger;
use App\Domain\Documents\Models\AttestationBlock;
use App\Domain\Members\Models\Member;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/cga/members/{member}/attestation-blocks : blocage manuel de l'attestation (RG-DOC-10),
 * origine (alerte ou initiative) et motif obligatoire ; gérant (attestations.block).
 */
class BlockAttestationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('block', [AttestationBlock::class, $this->member()]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'trigger' => ['required', 'string', Rule::enum(AttestationBlockTrigger::class)],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    public function trigger(): AttestationBlockTrigger
    {
        return AttestationBlockTrigger::from((string) $this->validated('trigger'));
    }

    public function reason(): string
    {
        return (string) $this->validated('reason');
    }

    /**
     * @throws AuthorizationException route sans dossier
     */
    public function member(): Member
    {
        $member = $this->route('member');

        return $member instanceof Member ? $member : throw new AuthorizationException;
    }
}
