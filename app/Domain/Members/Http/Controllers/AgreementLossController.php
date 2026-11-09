<?php

namespace App\Domain\Members\Http\Controllers;

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\RadiationReason;
use App\Domain\Members\Models\Member;
use App\Domain\Organizations\Enums\AgreementStatus;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * GET /api/v1/cga/agreement-loss/members : après une perte d'agrément (F-ORG-07), état des adhérents du
 * portefeuille CGA à radier, avec le motif proposé « Perte d'agrément du CGA » et la radiation déjà
 * engagée. La plateforme ne radie rien : chaque radiation est saisie puis validée dossier par dossier
 * (RG-ADH-18). Vide tant que l'agrément n'est pas retiré.
 */
class AgreementLossController extends BaseApiController
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): JsonResponse
    {
        return $this->handle(function (): JsonResponse {
            Gate::authorize('listAgreementLoss', Member::class);
            $cga = $this->context->organization();
            $withdrawn = $cga->agreement_status === AgreementStatus::Withdrawn;

            $members = ! $withdrawn ? collect() : Member::query()
                ->regulatory()
                ->whereIn('status', [MemberStatus::Active, MemberStatus::RadiationPending])
                ->with('currentRadiation')
                ->orderBy('company_name')
                ->get();

            return response()->json([
                'data' => $members->map(static fn (Member $member): array => [
                    'id' => $member->id,
                    'company_name' => $member->company_name,
                    'ncc' => $member->ncc,
                    'membership_number' => $member->membership_number,
                    'regime' => $member->regime->value,
                    'status' => $member->status->value,
                    'radiation' => $member->currentRadiation === null ? null : [
                        'id' => $member->currentRadiation->id,
                        'status' => $member->currentRadiation->status->value,
                        'reason' => $member->currentRadiation->reason->value,
                    ],
                ])->values(),
                'meta' => [
                    'agreement_withdrawn' => $withdrawn,
                    'withdrawn_at' => $cga->agreement_withdrawn_at?->toDateString(),
                    'proposed_reason' => ['code' => RadiationReason::AgreementLoss->value, 'label' => RadiationReason::AgreementLoss->label()],
                ],
            ]);
        }, action: 'members.agreement_loss');
    }
}
