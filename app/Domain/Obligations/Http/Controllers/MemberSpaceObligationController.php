<?php

namespace App\Domain\Obligations\Http\Controllers;

use App\Domain\Obligations\Actions\DeclareInstance;
use App\Domain\Obligations\Enums\InstanceStatus;
use App\Domain\Obligations\Http\Requests\DeclareInstanceRequest;
use App\Domain\Obligations\Http\Resources\ObligationInstanceResource;
use App\Domain\Obligations\Models\MemberObligation;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Domain\Obligations\Support\ObligationSummary;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/member/obligations : « Mes obligations » dans l'espace de l'adhérent (F-OBL-05) — les 4 blocs
 * en lecture avec leur « Total dû » (RG-OBL-08), « Signaler comme fait / payé » (RG-OBL-09).
 */
class MemberSpaceObligationController extends BaseApiController
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function (): AnonymousResourceCollection {
            Gate::authorize('viewOwn', MemberObligation::class);
            $instances = ObligationInstance::query()
                ->with('payments.proof')
                ->where('member_id', $this->context->memberId())
                ->where('status', '<>', InstanceStatus::Cancelled)
                ->orderBy('due_date')
                ->orderBy('kind')
                ->orderBy('label')
                ->get();

            return ObligationInstanceResource::collection($instances->map(
                static fn (ObligationInstance $instance): ObligationInstanceResource => (new ObligationInstanceResource($instance))->forMember(),
            ))->additional(['summary' => ObligationSummary::byCategory($instances, CarbonImmutable::today())]);
        }, action: 'member.obligations.index');
    }

    public function declare(DeclareInstanceRequest $request, ObligationInstance $obligationInstance, DeclareInstance $declare): ObligationInstanceResource|JsonResponse
    {
        return $this->handle(function () use ($request, $obligationInstance, $declare): ObligationInstanceResource {
            $instance = $declare->execute($obligationInstance, $request->amount(), $request->paidOn(), $request->proof(), $this->currentUser());
            $this->logSubject($instance);

            return (new ObligationInstanceResource($instance->load('payments.proof')))->forMember();
        }, action: 'member.obligations.declare');
    }
}
