<?php

namespace App\Domain\Closing\Http\Controllers;

use App\Domain\Closing\Actions\AssignClosingItems;
use App\Domain\Closing\Actions\MarkClosingStepDone;
use App\Domain\Closing\Actions\MarkClosingStepsDone;
use App\Domain\Closing\Actions\UpdateClosingItem;
use App\Domain\Closing\Enums\ClosingStep;
use App\Domain\Closing\Http\Requests\ClosingBoardRequest;
use App\Domain\Closing\Http\Requests\ClosingItemsRequest;
use App\Domain\Closing\Http\Requests\ClosingSelectionRequest;
use App\Domain\Closing\Http\Requests\MarkClosingStepRequest;
use App\Domain\Closing\Http\Requests\UpdateClosingItemRequest;
use App\Domain\Closing\Http\Resources\ClosingAgentResource;
use App\Domain\Closing\Http\Resources\ClosingCampaignResource;
use App\Domain\Closing\Http\Resources\ClosingItemResource;
use App\Domain\Closing\Models\ClosingCampaign;
use App\Domain\Closing\Models\ClosingCampaignItem;
use App\Domain\Closing\Support\ClosingProgress;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Support\Config\SettingsService;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * /api/v1/cga/closing-campaigns : campagnes de clôture du centre (/cga/cloture, F-OBL-08) — exercices,
 * calendrier et tableau de suivi (dossiers par étape, retards, RG-OBL-11), nombres de SMT et d'états
 * financiers établis et transmis du portefeuille CGA (RG-OBL-12), dossiers, « Marquer l'étape faite »,
 * livrable et agent d'un dossier.
 */
class ClosingCampaignController extends BaseApiController
{
    public function index(): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function (): AnonymousResourceCollection {
            Gate::authorize('view', ClosingCampaign::class);

            return ClosingCampaignResource::collection(ClosingCampaign::query()->orderByDesc('fiscal_year')->get());
        }, action: 'closing_campaigns.index');
    }

    /**
     * Agents actifs du centre, par nom : à qui affecter des dossiers, et filtre par agent du tableau.
     */
    public function agents(TenantContext $context): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($context): AnonymousResourceCollection {
            Gate::authorize('view', ClosingCampaign::class);

            $agents = Membership::query()
                ->with('user')
                ->where('organization_id', $context->organizationId())
                ->whereNull('member_id')
                ->where('status', MembershipStatus::Active)
                ->get()
                ->map(static fn (Membership $membership): User => $membership->user)
                ->unique('id')
                ->sortBy(static fn (User $user): string => $user->fullName())
                ->values();

            return ClosingAgentResource::collection($agents);
        }, action: 'closing_campaigns.agents');
    }

    public function show(ClosingBoardRequest $request, ClosingCampaign $closingCampaign): ClosingCampaignResource|JsonResponse
    {
        return $this->handle(function () use ($request, $closingCampaign): ClosingCampaignResource {
            $items = ClosingCampaignItem::query()
                ->where('campaign_id', $closingCampaign->id)
                ->when($request->portfolio(), static fn (Builder $query, mixed $portfolio) => $query->where('portfolio', $portfolio))
                ->when($request->assignedTo(), static fn (Builder $query, string $agent) => $query->where('assigned_to', $agent));

            return (new ClosingCampaignResource($closingCampaign))->additional(['meta' => [
                'board' => ClosingProgress::board($closingCampaign, $items, CarbonImmutable::today()),
                'report' => ClosingProgress::report($closingCampaign),
            ]]);
        }, action: 'closing_campaigns.show');
    }

    public function items(ClosingItemsRequest $request, ClosingCampaign $closingCampaign, SettingsService $settings): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($request, $closingCampaign, $settings): AnonymousResourceCollection {
            $items = QueryBuilder::for(ClosingCampaignItem::query()->with(['campaign', 'member', 'assignee', 'filingFile'])->where('campaign_id', $closingCampaign->id), $request->listQuery())
                ->allowedFilters(...$request->queryFilters($closingCampaign, CarbonImmutable::today()))
                ->allowedSorts(...$request->sorts())
                ->defaultSort('created_at')
                ->paginate($request->perPage($settings), ['*'], 'page', $request->page());
            $manages = Gate::allows('manage', ClosingCampaign::class);

            return ClosingItemResource::collection($items->through(
                static fn (ClosingCampaignItem $item): ClosingItemResource => (new ClosingItemResource($item))->managedBy($manages),
            ));
        }, action: 'closing_campaigns.items');
    }

    public function markStep(MarkClosingStepRequest $request, ClosingCampaign $closingCampaign, ClosingCampaignItem $item, ClosingStep $step, MarkClosingStepDone $mark): ClosingItemResource|JsonResponse
    {
        return $this->handle(function () use ($request, $item, $step, $mark): ClosingItemResource {
            $item = $mark->execute($item, $step, $request->doneOn(), $request->reference(), $request->receipt(), $this->currentUser());
            $this->logSubject($item);

            return $this->resource($item);
        }, action: 'closing_campaigns.items.step');
    }

    public function updateItem(UpdateClosingItemRequest $request, ClosingCampaign $closingCampaign, ClosingCampaignItem $item, UpdateClosingItem $update): ClosingItemResource|JsonResponse
    {
        return $this->handle(function () use ($request, $item, $update): ClosingItemResource {
            $item = $update->execute($item, $request->deliverable(), $request->assignedTo());
            $this->logSubject($item);

            return $this->resource($item);
        }, action: 'closing_campaigns.items.update');
    }

    public function bulkStep(ClosingSelectionRequest $request, ClosingCampaign $closingCampaign, ClosingStep $step, MarkClosingStepsDone $mark): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($request, $closingCampaign, $step, $mark): AnonymousResourceCollection {
            $result = $mark->execute($closingCampaign, $request->itemIds(), $step, $request->doneOn());

            return $this->collection($result['done'])->additional(['meta' => ['skipped' => $result['skipped']]]);
        }, action: 'closing_campaigns.bulk.step');
    }

    public function bulkAssign(ClosingSelectionRequest $request, ClosingCampaign $closingCampaign, AssignClosingItems $assign): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(fn (): AnonymousResourceCollection => $this->collection(
            $assign->execute($closingCampaign, $request->itemIds(), $request->assignedTo()),
        ), action: 'closing_campaigns.bulk.assign');
    }

    /**
     * @param  list<ClosingCampaignItem>  $items
     */
    private function collection(array $items): AnonymousResourceCollection
    {
        $items = (new Collection($items))->load(['campaign', 'member', 'assignee', 'filingFile']);

        return ClosingItemResource::collection($items->map(static fn (ClosingCampaignItem $item): ClosingItemResource => (new ClosingItemResource($item))->managedBy(true)));
    }

    private function resource(ClosingCampaignItem $item): ClosingItemResource
    {
        return (new ClosingItemResource($item->load(['campaign', 'member', 'assignee', 'filingFile'])))->managedBy(true);
    }
}
