<?php

namespace App\Domain\Obligations\Http\Controllers;

use App\Domain\Obligations\Http\Requests\ObligationPlanningRequest;
use App\Domain\Obligations\Http\Requests\PlanningMembersRequest;
use App\Domain\Obligations\Http\Resources\PlanningLineResource;
use App\Domain\Obligations\Http\Resources\PlanningMemberResource;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Domain\Obligations\Support\ObligationPlanning;
use App\Support\Config\SettingsService;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * /api/v1/cga/obligation-planning : planning des échéances du portefeuille (/cga/echeances, F-OBL-06) —
 * lignes « date — obligation — n adhérents » avec faits / à faire, indicateur « Échéances sous n
 * jours », adhérents concernés par une ligne.
 */
class ObligationPlanningController extends BaseApiController
{
    public function __construct(
        private readonly ObligationPlanning $planning,
        private readonly SettingsService $settings,
        private readonly TenantContext $context,
    ) {}

    public function index(ObligationPlanningRequest $request): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($request): AnonymousResourceCollection {
            $organizationId = $this->context->organizationId();
            $today = CarbonImmutable::today();
            $from = $request->dueFrom() ?? $today;
            $to = $request->dueTo() ?? $from->addMonthsNoOverflow((int) $this->settings->get('obligations.horizon_months', $organizationId));

            return PlanningLineResource::collection($this->planning->groups($from, $to, $request->typeId(), $request->progress()))
                ->additional(['meta' => [
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                    'upcoming' => $this->planning->upcoming($today, (int) $this->settings->get('obligations.upcoming_days', $organizationId)),
                ]]);
        }, action: 'obligation_planning.index');
    }

    public function members(PlanningMembersRequest $request): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($request): AnonymousResourceCollection {
            $instances = $this->planning->group($request->dueDate(), $request->kind(), $request->typeId(), $request->label())
                ->with('member')
                ->get()
                ->sortBy(static fn (ObligationInstance $instance): string => $instance->member->company_name)
                ->values();

            return PlanningMemberResource::collection($instances);
        }, action: 'obligation_planning.members');
    }
}
