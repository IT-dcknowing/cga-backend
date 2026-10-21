<?php

namespace App\Domain\Members\Http\Controllers;

use App\Domain\Auth\Support\DevMessages;
use App\Domain\Members\Actions\InviteMemberAccess;
use App\Domain\Members\Actions\SetMemberAccessStatus;
use App\Domain\Members\Http\Requests\InviteMemberAccessRequest;
use App\Domain\Members\Http\Requests\MemberAccessRequest;
use App\Domain\Members\Http\Resources\MemberAccessResource;
use App\Domain\Members\Models\Member;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Actions\ResendInvitation;
use App\Domain\Users\Models\Membership;
use App\Support\Http\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/cga/members/{member}/accesses : onglet « Accès en ligne » du dossier (F-ADH-09) — accès
 * principal et secondaires, consultés par les agents, invités, relancés et désactivés par le gérant.
 */
class MemberAccessController extends BaseApiController
{
    public function index(Member $member): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($member): AnonymousResourceCollection {
            Gate::authorize('view', $member);
            $manageable = Gate::allows('manageAccesses', $member);
            $accesses = $member->accesses()->with(['user', 'invitations'])->orderByRaw("role = 'member_owner' desc")->orderBy('created_at')->get();

            return MemberAccessResource::collection($accesses->map(static fn (Membership $access): MemberAccessResource => new MemberAccessResource($access, $manageable)));
        }, action: 'members.accesses.index');
    }

    public function store(InviteMemberAccessRequest $request, Member $member, InviteMemberAccess $invite, DevMessages $devMessages): JsonResponse
    {
        return $this->handle(function () use ($request, $member, $invite, $devMessages): JsonResponse {
            $invited = $invite->execute($member, $request->identity(), $request->role(), $request->channel(), $this->currentUser());
            $access = $member->accesses()->where('user_id', $invited->user->id)->sole();
            $this->logSubject($access);

            return $this->resource($access)->additional($devMessages->invitation($invited->channel, $invited->user, $invited->link))->response()->setStatusCode(201);
        }, action: 'members.accesses.store');
    }

    public function resend(MemberAccessRequest $request, Member $member, Membership $access, ResendInvitation $resend, DevMessages $devMessages): JsonResponse
    {
        return $this->handle(function () use ($request, $member, $access, $resend, $devMessages): JsonResponse {
            $link = $resend->execute(Organization::query()->findOrFail($member->organization_id), $access->user, $request->channel(), $member);
            $this->logSubject($access);

            return $this->resource($access)->additional($devMessages->invitation($request->channel(), $access->user, $link))->response();
        }, action: 'members.accesses.resend');
    }

    public function disable(MemberAccessRequest $request, Member $member, Membership $access, SetMemberAccessStatus $status): MemberAccessResource|JsonResponse
    {
        return $this->handle(function () use ($access, $status): MemberAccessResource {
            $access = $status->disable($access);
            $this->logSubject($access);

            return $this->resource($access);
        }, action: 'members.accesses.disable');
    }

    public function enable(MemberAccessRequest $request, Member $member, Membership $access, SetMemberAccessStatus $status): MemberAccessResource|JsonResponse
    {
        return $this->handle(function () use ($member, $access, $status): MemberAccessResource {
            $access = $status->enable($member, $access);
            $this->logSubject($access);

            return $this->resource($access);
        }, action: 'members.accesses.enable');
    }

    private function resource(Membership $access): MemberAccessResource
    {
        return new MemberAccessResource($access->refresh()->load(['user', 'invitations']), true);
    }
}
