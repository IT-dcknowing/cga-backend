<?php

namespace App\Domain\Documents\Http\Requests;

use App\Domain\Documents\Models\AttestationBlock;
use App\Domain\Members\Models\Member;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/v1/cga/members/{member}/attestation-blocks/release : déblocage de l'attestation
 * (RG-DOC-10), motif obligatoire ; gérant (attestations.block).
 */
class ReleaseAttestationBlockRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:1000'],
        ];
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
