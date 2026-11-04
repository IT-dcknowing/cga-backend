<?php

namespace App\Domain\Statistics\Http\Controllers;

use App\Domain\Statistics\Dashboards\MemberDashboard;
use App\Domain\Statistics\Http\Requests\MemberDashboardRequest;
use App\Domain\Statistics\Http\Resources\MemberDashboardResource;
use App\Support\Http\BaseApiController;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * GET /member/dashboard : tableau de bord de l'adhérent (F-DSH-03, accueil de l'espace adhérent).
 */
class MemberDashboardController extends BaseApiController
{
    public function show(MemberDashboardRequest $request, MemberDashboard $dashboard): MemberDashboardResource|JsonResponse
    {
        return $this->handle(fn (): MemberDashboardResource => new MemberDashboardResource($dashboard->build(CarbonImmutable::today())), action: 'member.dashboard');
    }
}
