<?php

namespace App\Domain\Obligations\Http\Controllers;

use App\Domain\Members\Models\Member;
use App\Domain\Obligations\Actions\AddMemberObligation;
use App\Domain\Obligations\Actions\ReviewMemberObligations;
use App\Domain\Obligations\Http\Requests\AddMemberObligationRequest;
use App\Domain\Obligations\Http\Requests\ReviewMemberObligationsRequest;
use App\Domain\Obligations\Http\Resources\MemberObligationResource;
use App\Domain\Obligations\Models\MemberObligation;
use App\Domain\Obligations\Models\ObligationType;
use App\Support\Http\BaseApiController;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/cga/members/{member}/obligations : obligations attribuées à un dossier (F-OBL-02) —
 * proposées par la plateforme, confirmées ou retirées par le CGA, ajoutées à la main.
 */
class MemberObligationController extends BaseApiController
{
    public function index(Member $member): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($member): AnonymousResourceCollection {
            Gate::authorize('viewAny', [MemberObligation::class, $member]);

            return $this->collection($member);
        }, action: 'member_obligations.index');
    }

    public function store(AddMemberObligationRequest $request, Member $member, AddMemberObligation $add): JsonResponse
    {
        return $this->handle(function () use ($request, $member, $add): JsonResponse {
            $type = ObligationType::query()->find((string) $request->validated('obligation_type_id'))
                ?? throw new BusinessRuleException('obligation_type_unavailable');
            $obligation = $add->execute($member, $type, $this->currentUser());
            $this->logSubject($obligation);

            return (new MemberObligationResource($obligation->load('type')))->managedBy(true)->response()->setStatusCode(201);
        }, action: 'member_obligations.store');
    }

    public function review(ReviewMemberObligationsRequest $request, Member $member, ReviewMemberObligations $review): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($request, $member, $review): AnonymousResourceCollection {
            $review->execute($member, $request->confirmed(), $request->removed(), $this->currentUser());
            $this->logSubject($member);

            return $this->collection($member);
        }, action: 'member_obligations.review');
    }

    private function collection(Member $member): AnonymousResourceCollection
    {
        $manages = Gate::allows('manage', [MemberObligation::class, $member]);
        $obligations = MemberObligation::query()
            ->with('type')
            ->where('member_id', $member->id)
            ->orderByRaw("CASE status WHEN 'suggested' THEN 0 WHEN 'active' THEN 1 ELSE 2 END")
            ->orderBy('created_at')
            ->get();

        return MemberObligationResource::collection($obligations->map(
            static fn (MemberObligation $obligation): MemberObligationResource => (new MemberObligationResource($obligation))->managedBy($manages),
        ));
    }
}
