<?php

namespace App\Domain\Users\Http\Controllers;

use App\Domain\Auth\Support\DevMessages;
use App\Domain\Users\Actions\ChangeAgentRoles;
use App\Domain\Users\Actions\DisableAgent;
use App\Domain\Users\Actions\EnableAgent;
use App\Domain\Users\Actions\InviteAgent;
use App\Domain\Users\Actions\ResendInvitation;
use App\Domain\Users\Http\Requests\AgentRolesRequest;
use App\Domain\Users\Http\Requests\InviteAgentRequest;
use App\Domain\Users\Http\Requests\ListAgentsRequest;
use App\Domain\Users\Http\Requests\ResendInvitationRequest;
use App\Domain\Users\Http\Resources\AgentResource;
use App\Domain\Users\Models\User;
use App\Domain\Users\Support\AgentDirectory;
use App\Domain\Users\Support\AgentSpace;
use App\Support\Config\SettingsService;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * /api/v1/cga/users (gérant) et /api/v1/network/users (Présidente) : agents de l'organisation de
 * l'espace actif (F-ORG-09, F-ORG-10) — liste, invitation, rôles, désactivation, réactivation,
 * renvoi du lien d'activation.
 */
class AgentController extends BaseApiController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AgentDirectory $directory,
    ) {}

    public function index(ListAgentsRequest $request, SettingsService $settings): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($request, $settings): AnonymousResourceCollection {
            $agents = QueryBuilder::for($this->directory->query($this->context->organization()), $request->listQuery())
                ->allowedFilters(...$request->queryFilters($this->context))
                ->allowedSorts(...$request->sorts())
                ->defaultSort('last_name')
                ->paginate($request->perPage($settings), ['*'], 'page', $request->page());

            return AgentResource::collection($agents);
        }, action: 'users.index');
    }

    public function store(InviteAgentRequest $request, InviteAgent $invite, DevMessages $devMessages): JsonResponse
    {
        return $this->handle(function () use ($request, $invite, $devMessages): JsonResponse {
            $organization = $this->context->organization();
            $invited = $invite->execute($organization, $request->identity(), $request->roles(), $request->channel(), $this->currentUser());
            $agent = $this->directory->find($organization, $invited->user->id);
            $this->logSubject($agent);

            return (new AgentResource($agent))->additional($devMessages->invitation($invited->channel, $agent, $invited->link))->response()->setStatusCode(201);
        }, action: 'users.store');
    }

    public function show(Request $request, User $agent): AgentResource|JsonResponse
    {
        return $this->handle(function () use ($request, $agent): AgentResource {
            Gate::authorize('view', [$agent, AgentSpace::of($request)]);

            return new AgentResource($agent);
        }, action: 'users.show');
    }

    public function updateRoles(AgentRolesRequest $request, User $agent, ChangeAgentRoles $change): AgentResource|JsonResponse
    {
        return $this->handle(function () use ($request, $agent, $change): AgentResource {
            $change->execute($this->context->organization(), $agent, $request->roles(), $this->currentUser());

            return $this->fresh($agent);
        }, action: 'users.roles');
    }

    public function disable(Request $request, User $agent, DisableAgent $disable): AgentResource|JsonResponse
    {
        return $this->handle(function () use ($request, $agent, $disable): AgentResource {
            Gate::authorize('update', [$agent, AgentSpace::of($request)]);
            $disable->execute($this->context->organization(), $agent, $this->currentUser());

            return $this->fresh($agent);
        }, action: 'users.disable');
    }

    public function enable(Request $request, User $agent, EnableAgent $enable): AgentResource|JsonResponse
    {
        return $this->handle(function () use ($request, $agent, $enable): AgentResource {
            Gate::authorize('update', [$agent, AgentSpace::of($request)]);
            $enable->execute($this->context->organization(), $agent);

            return $this->fresh($agent);
        }, action: 'users.enable');
    }

    public function resendInvitation(ResendInvitationRequest $request, User $agent, ResendInvitation $resend, DevMessages $devMessages): JsonResponse
    {
        return $this->handle(function () use ($request, $agent, $resend, $devMessages): JsonResponse {
            $link = $resend->execute($this->context->organization(), $agent, $request->channel());

            return $this->fresh($agent)->additional($devMessages->invitation($request->channel(), $agent, $link))->response();
        }, action: 'users.invitation');
    }

    private function fresh(User $agent): AgentResource
    {
        return new AgentResource($this->directory->find($this->context->organization(), $agent->id));
    }
}
