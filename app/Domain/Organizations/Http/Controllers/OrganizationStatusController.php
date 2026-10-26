<?php

namespace App\Domain\Organizations\Http\Controllers;

use App\Domain\Organizations\Actions\ChangeSubscription;
use App\Domain\Organizations\Actions\ReactivateCga;
use App\Domain\Organizations\Actions\SuspendCga;
use App\Domain\Organizations\Actions\WithdrawAgreement;
use App\Domain\Organizations\Http\Requests\StatusReasonRequest;
use App\Domain\Organizations\Http\Requests\SubscriptionRequest;
use App\Domain\Organizations\Http\Requests\WithdrawAgreementRequest;
use App\Domain\Organizations\Http\Resources\OrganizationResource;
use App\Domain\Organizations\Models\Organization;
use App\Support\Http\BaseApiController;
use Illuminate\Http\JsonResponse;

/**
 * /api/v1/network/organizations/{organization}/… : changements d'état d'un CGA, chacun tracé dans
 * son historique — abonnement (F-ORG-05), suspension et réactivation (F-ORG-06), perte d'agrément
 * (F-ORG-07), ces trois derniers par la Présidente seulement.
 */
class OrganizationStatusController extends BaseApiController
{
    public function subscription(SubscriptionRequest $request, Organization $organization, ChangeSubscription $change): OrganizationResource|JsonResponse
    {
        return $this->handle(function () use ($request, $organization, $change): OrganizationResource {
            return $this->resource($change->execute($organization, $request->status(), $request->reason(), $this->currentUser()));
        }, action: 'organizations.subscription');
    }

    public function suspend(StatusReasonRequest $request, Organization $organization, SuspendCga $suspend): OrganizationResource|JsonResponse
    {
        return $this->handle(function () use ($request, $organization, $suspend): OrganizationResource {
            return $this->resource($suspend->execute($organization, $request->reason(), $this->currentUser()));
        }, action: 'organizations.suspend');
    }

    public function reactivate(StatusReasonRequest $request, Organization $organization, ReactivateCga $reactivate): OrganizationResource|JsonResponse
    {
        return $this->handle(function () use ($request, $organization, $reactivate): OrganizationResource {
            return $this->resource($reactivate->execute($organization, $request->reason(), $this->currentUser()));
        }, action: 'organizations.reactivate');
    }

    public function withdrawAgreement(WithdrawAgreementRequest $request, Organization $organization, WithdrawAgreement $withdraw): OrganizationResource|JsonResponse
    {
        return $this->handle(function () use ($request, $organization, $withdraw): OrganizationResource {
            return $this->resource($withdraw->execute($organization, $request->withdrawnAt(), $request->reason(), $this->currentUser()));
        }, action: 'organizations.withdraw-agreement');
    }

    private function resource(Organization $organization): OrganizationResource
    {
        return new OrganizationResource($organization->load(['regionalDirectorate', 'statusEvents.actor', 'manager.user']));
    }
}
