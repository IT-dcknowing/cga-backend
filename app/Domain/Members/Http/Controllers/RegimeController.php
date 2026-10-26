<?php

namespace App\Domain\Members\Http\Controllers;

use App\Domain\Members\Actions\ChangeRegime;
use App\Domain\Members\Http\Requests\ChangeRegimeRequest;
use App\Domain\Members\Http\Resources\MemberResource;
use App\Domain\Members\Http\Resources\RegimeChangeResource;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\MemberRegimeChange;
use App\Support\Http\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/cga/members/{member}/regimes : historique daté des régimes d'un dossier et « Changer de
 * régime » (F-ADH-05, RG-ADH-08).
 */
class RegimeController extends BaseApiController
{
    public function index(Member $member): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($member): AnonymousResourceCollection {
            Gate::authorize('view', $member);

            $changes = MemberRegimeChange::query()
                ->with(['justification', 'author'])
                ->where('member_id', $member->id)
                ->orderByDesc('effective_date')
                ->orderByDesc('created_at')
                ->get();

            return RegimeChangeResource::collection($changes);
        }, action: 'members.regimes.index');
    }

    public function store(ChangeRegimeRequest $request, Member $member, ChangeRegime $change): MemberResource|JsonResponse
    {
        return $this->handle(function () use ($request, $member, $change): MemberResource {
            $member = $change->execute($member, $request->regime(), $request->effectiveDate(), $request->reason(), $request->justification(), $this->currentUser());
            $this->logSubject($member);

            return new MemberResource($member->load(MemberController::WITH));
        }, action: 'members.regimes.store');
    }
}
