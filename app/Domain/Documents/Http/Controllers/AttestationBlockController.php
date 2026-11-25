<?php

namespace App\Domain\Documents\Http\Controllers;

use App\Domain\Documents\Actions\BlockAttestation;
use App\Domain\Documents\Actions\ReleaseAttestationBlock;
use App\Domain\Documents\Http\Requests\BlockAttestationRequest;
use App\Domain\Documents\Http\Requests\ReleaseAttestationBlockRequest;
use App\Domain\Documents\Http\Resources\AttestationBlockResource;
use App\Domain\Documents\Models\AttestationBlock;
use App\Domain\Members\Models\Member;
use App\Support\Http\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/cga/members/{member}/attestation-blocks : blocage manuel de l'attestation d'un adhérent
 * (RG-DOC-10) — historique des blocages et déblocages, blocage motivé, déblocage motivé. Jamais de
 * blocage automatique (CR-04).
 */
class AttestationBlockController extends BaseApiController
{
    private const WITH = ['blocker', 'unblocker'];

    public function index(Member $member): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($member): AnonymousResourceCollection {
            Gate::authorize('viewAny', [AttestationBlock::class, $member]);

            return AttestationBlockResource::collection(AttestationBlock::query()
                ->with(self::WITH)
                ->where('member_id', $member->id)
                ->orderByDesc('blocked_at')
                ->get());
        }, action: 'attestation_blocks.index');
    }

    public function store(BlockAttestationRequest $request, Member $member, BlockAttestation $block): JsonResponse
    {
        return $this->handle(function () use ($request, $member, $block): JsonResponse {
            $attestationBlock = $block->execute($member, $request->trigger(), $request->reason(), $this->currentUser());

            return (new AttestationBlockResource($attestationBlock->load(self::WITH)))->response()->setStatusCode(201);
        }, action: 'attestation_blocks.store');
    }

    public function release(ReleaseAttestationBlockRequest $request, Member $member, ReleaseAttestationBlock $release): AttestationBlockResource|JsonResponse
    {
        return $this->handle(function () use ($request, $member, $release): AttestationBlockResource {
            $attestationBlock = $release->execute($member, $request->reason(), $this->currentUser());

            return new AttestationBlockResource($attestationBlock->load(self::WITH));
        }, action: 'attestation_blocks.release');
    }
}
