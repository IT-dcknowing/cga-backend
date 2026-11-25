<?php

namespace App\Domain\Notifications\Http\Controllers;

use App\Domain\Notifications\Actions\MessageCampaigns;
use App\Domain\Notifications\Http\Requests\CampaignAudienceRequest;
use App\Domain\Notifications\Http\Requests\MessageCampaignRequest;
use App\Domain\Notifications\Http\Requests\RejectCampaignRequest;
use App\Domain\Notifications\Http\Resources\MessageCampaignResource;
use App\Domain\Notifications\Models\MessageCampaign;
use App\Domain\Notifications\Models\MessageLog;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/cga/campaigns : envois groupés du centre (/cga/communications, F-COM-03) — historique (date,
 * objet, canaux, destinataires, délivrance), préparation, envoi, validation ou refus par le gérant au-delà
 * du seuil (RG-COM-01), quota SMS (RG-COM-02).
 */
class MessageCampaignController extends BaseApiController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly MessageCampaigns $campaigns,
    ) {}

    public function index(): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function (): AnonymousResourceCollection {
            Gate::authorize('view', MessageCampaign::class);
            $campaigns = MessageCampaign::query()->latest()->limit((int) config('messaging.campaign_history'))->get();
            $delivery = MessageLog::query()->whereIn('campaign_id', $campaigns->pluck('id')->all())
                ->selectRaw('campaign_id, status, count(*) as total')->groupBy('campaign_id', 'status')->get()
                ->groupBy('campaign_id');

            $organizationId = $this->context->organizationId();

            return MessageCampaignResource::collection($campaigns->map(static fn (MessageCampaign $campaign): MessageCampaignResource => (new MessageCampaignResource($campaign))->withDelivery(
                (array) ($delivery->get($campaign->id)?->mapWithKeys(static fn (MessageLog $log): array => [$log->status => (int) $log->getAttribute('total')])->all() ?? []),
            )))->additional(['meta' => [
                'approval_threshold' => $this->campaigns->approvalThreshold($organizationId),
                'sms_remaining' => $this->campaigns->remainingSms($organizationId, CarbonImmutable::now()),
                'can' => ['send' => Gate::allows('send', MessageCampaign::class), 'approve' => Gate::allows('approve', MessageCampaign::class)],
            ]]);
        }, action: 'campaigns.index');
    }

    /**
     * Aperçu d'un envoi (étapes 1 et 2 de l'assistant) : destinataires comptés en direct, quota SMS.
     */
    public function preview(CampaignAudienceRequest $request): JsonResponse
    {
        return $this->handle(fn (): JsonResponse => response()->json(['data' => $this->campaigns->audience(
            $this->context->organizationId(),
            $request->channels(),
            $request->filter(),
            CarbonImmutable::now(),
        )]), action: 'campaigns.preview');
    }

    public function store(MessageCampaignRequest $request, MessageCampaigns $campaigns): MessageCampaignResource|JsonResponse
    {
        return $this->handle(function () use ($request, $campaigns): JsonResponse {
            $campaign = $campaigns->prepare($this->context->organizationId(), $request->title(), $request->body(), $request->channels(), $request->filter(), $this->currentUser());
            $this->logSubject($campaign);

            return (new MessageCampaignResource($campaign))->response()->setStatusCode(201);
        }, action: 'campaigns.store');
    }

    public function send(MessageCampaign $campaign, MessageCampaigns $campaigns): MessageCampaignResource|JsonResponse
    {
        return $this->handle(function () use ($campaign, $campaigns): MessageCampaignResource {
            Gate::authorize('send', MessageCampaign::class);

            // Envoyée par qui valide (le gérant), elle part aussitôt, validée par lui (RG-COM-01).
            $approver = Gate::allows('approve', MessageCampaign::class) ? $this->currentUser() : null;

            return new MessageCampaignResource($campaigns->submit($campaign, CarbonImmutable::now(), $approver));
        }, action: 'campaigns.send');
    }

    public function approve(MessageCampaign $campaign, MessageCampaigns $campaigns): MessageCampaignResource|JsonResponse
    {
        return $this->handle(function () use ($campaign, $campaigns): MessageCampaignResource {
            Gate::authorize('approve', MessageCampaign::class);

            return new MessageCampaignResource($campaigns->approve($campaign, $this->currentUser(), CarbonImmutable::now()));
        }, action: 'campaigns.approve');
    }

    public function reject(RejectCampaignRequest $request, MessageCampaign $campaign, MessageCampaigns $campaigns): MessageCampaignResource|JsonResponse
    {
        return $this->handle(fn (): MessageCampaignResource => new MessageCampaignResource(
            $campaigns->reject($campaign, $request->reason(), $this->currentUser()),
        ), action: 'campaigns.reject');
    }
}
