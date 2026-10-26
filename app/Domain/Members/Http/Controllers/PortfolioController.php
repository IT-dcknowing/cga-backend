<?php

namespace App\Domain\Members\Http\Controllers;

use App\Domain\Members\Actions\MoveToCgaPortfolio;
use App\Domain\Members\Http\Requests\MoveToCgaRequest;
use App\Domain\Members\Http\Resources\MemberResource;
use App\Domain\Members\Models\Member;
use App\Support\Http\BaseApiController;
use Illuminate\Http\JsonResponse;

/**
 * /api/v1/cga/members/{member}/portfolio : bascule d'un dossier du Cabinet vers le portefeuille CGA
 * (RG-PTF-03). La bascule inverse est une radiation validée avec l'issue « basculer dans le
 * portefeuille Cabinet » (RG-PTF-04, RadiationController).
 */
class PortfolioController extends BaseApiController
{
    public function moveToCga(MoveToCgaRequest $request, Member $member, MoveToCgaPortfolio $move): MemberResource|JsonResponse
    {
        return $this->handle(function () use ($request, $member, $move): MemberResource {
            $member = $move->execute($member, $request->contractSignedOn(), $request->contract(), $request->referrerId(), $request->reason(), $this->currentUser(), $request->entryFeePayment());
            $this->logSubject($member);

            return new MemberResource($member->load(MemberController::WITH));
        }, action: 'members.portfolio.cga');
    }
}
