<?php

namespace App\Domain\Closing\Http\Controllers;

use App\Domain\Closing\Enums\ClosingCampaignStatus;
use App\Domain\Closing\Http\Resources\ClosingItemResource;
use App\Domain\Closing\Models\ClosingCampaign;
use App\Domain\Closing\Models\ClosingCampaignItem;
use App\Domain\Members\Models\Member;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Clôture d'un dossier, exercice par exercice (F-OBL-08) : onglet « Clôture » du dossier
 * (/api/v1/cga/members/{member}/closing) et « /adherent/cloture » (/api/v1/member/closing, campagnes
 * ouvertes seulement, étapes et dates).
 */
class MemberClosingController extends BaseApiController
{
    public function __construct(private readonly TenantContext $context) {}

    public function forMember(Member $member): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($member): AnonymousResourceCollection {
            Gate::authorize('view', $member);
            Gate::authorize('view', ClosingCampaign::class);
            $manages = Gate::allows('manage', ClosingCampaign::class);

            return ClosingItemResource::collection($this->items($member->id, false)->map(
                static fn (ClosingCampaignItem $item): ClosingItemResource => (new ClosingItemResource($item))->managedBy($manages),
            ));
        }, action: 'closing.member');
    }

    public function own(): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function (): AnonymousResourceCollection {
            Gate::authorize('viewOwn', ClosingCampaign::class);

            return ClosingItemResource::collection($this->items((string) $this->context->memberId(), true)->map(
                static fn (ClosingCampaignItem $item): ClosingItemResource => (new ClosingItemResource($item))->forMember(),
            ));
        }, action: 'member.closing');
    }

    /**
     * @return Collection<int, ClosingCampaignItem>
     */
    private function items(string $memberId, bool $openOnly): Collection
    {
        return ClosingCampaignItem::query()
            ->with(['campaign', 'member', 'assignee', 'filingFile'])
            ->where('member_id', $memberId)
            ->when($openOnly, static fn (Builder $query) => $query->whereHas('campaign', static fn (Builder $campaign) => $campaign->where('status', ClosingCampaignStatus::Open)))
            ->get()
            ->sortByDesc(static fn (ClosingCampaignItem $item): int => $item->campaign->fiscal_year)
            ->values();
    }
}
