<?php

namespace App\Domain\Members\Http\Controllers;

use App\Domain\Members\Actions\CancelRadiation;
use App\Domain\Members\Actions\RequestRadiation;
use App\Domain\Members\Actions\ValidateRadiation;
use App\Domain\Members\Enums\RadiationInitiator;
use App\Domain\Members\Enums\RadiationOutcome;
use App\Domain\Members\Http\Requests\CancelRadiationRequest;
use App\Domain\Members\Http\Requests\RequestRadiationRequest;
use App\Domain\Members\Http\Requests\ValidateRadiationRequest;
use App\Domain\Members\Http\Resources\MemberResource;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Support\RadiationRules;
use App\Support\Http\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/cga/members/{member}/radiation : radiation d'un dossier du portefeuille CGA en deux temps
 * (F-ADH-06, RG-ADH-17) — demande, validation (clore ou basculer dans le portefeuille Cabinet),
 * annulation par le gérant (F-ADH-07) — et table des motifs par initiateur pour la modale. Toute
 * radiation passe par ces routes, saisie par un utilisateur habilité (RG-ADH-18).
 */
class RadiationController extends BaseApiController
{
    public function options(RadiationRules $rules): JsonResponse
    {
        return $this->handle(function () use ($rules): JsonResponse {
            Gate::authorize('viewAny', Member::class);

            return response()->json(['data' => [
                'initiators' => array_map(static fn (RadiationInitiator $initiator): array => [
                    'value' => $initiator->value,
                    'label' => $initiator->label(),
                    'sp_request_reference' => $rules->acceptsSpRequestReference($initiator),
                    'reasons' => array_map(static function ($reason) use ($rules, $initiator): array {
                        $proof = $rules->proof($initiator, $reason);

                        return [
                            'value' => $reason->value,
                            'label' => $reason->label(),
                            'proof' => $proof === null ? null : ['code' => $proof, 'label' => __("members.radiation.proofs.{$proof}")],
                            'immediate' => $rules->allowsImmediateValidation($initiator, $reason),
                        ];
                    }, $rules->reasons($initiator)),
                ], RadiationInitiator::cases()),
                'outcomes' => array_map(static fn (RadiationOutcome $outcome): array => ['value' => $outcome->value, 'label' => $outcome->label()], RadiationOutcome::cases()),
            ]]);
        }, action: 'members.radiation.options');
    }

    public function store(RequestRadiationRequest $request, Member $member, RequestRadiation $requestRadiation): JsonResponse
    {
        return $this->handle(function () use ($request, $member, $requestRadiation): JsonResponse {
            $immediate = $request->immediate();
            if ($immediate !== null) {
                $this->authorizeValidation($member, $immediate['outcome']);
            }
            $requestRadiation->execute($member, $request->demand(), $request->proof(), $immediate, $this->currentUser());

            return $this->member($member)->response()->setStatusCode(201);
        }, action: 'members.radiation.store');
    }

    public function validate(ValidateRadiationRequest $request, Member $member, ValidateRadiation $validate): MemberResource|JsonResponse
    {
        return $this->handle(function () use ($request, $member, $validate): MemberResource {
            $this->authorizeValidation($member, $request->outcome());
            $validate->execute($member, $request->outcome(), $request->reference(), $this->currentUser());

            return $this->member($member);
        }, action: 'members.radiation.validate');
    }

    public function cancel(CancelRadiationRequest $request, Member $member, CancelRadiation $cancel): MemberResource|JsonResponse
    {
        return $this->handle(function () use ($request, $member, $cancel): MemberResource {
            $cancel->execute($member, $request->reason(), $this->currentUser());

            return $this->member($member);
        }, action: 'members.radiation.cancel');
    }

    /**
     * Valider demande members.radiation.validate ; basculer dans le portefeuille Cabinet, en plus, le
     * droit de basculer (RG-PTF-04).
     */
    private function authorizeValidation(Member $member, RadiationOutcome $outcome): void
    {
        Gate::authorize('validateRadiation', $member);
        if ($outcome === RadiationOutcome::MovedToCabinet) {
            Gate::authorize('moveToCabinet', $member);
        }
    }

    private function member(Member $member): MemberResource
    {
        $this->logSubject($member);

        return new MemberResource($member->refresh()->load(MemberController::WITH));
    }
}
