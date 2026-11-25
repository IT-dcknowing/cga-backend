<?php

namespace App\Domain\Members\Http\Controllers;

use App\Domain\Members\Http\Resources\MemberHomeResource;
use App\Domain\Members\Models\Member;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * GET /api/v1/member/home : accueil de l'espace adhérent, version minimale (06-IMPLEMENTATION-PLAN
 * étape 2.11) — son dossier et l'identité de son CGA. Le tableau de bord complet (F-DSH-03) arrive
 * avec les obligations et les honoraires.
 */
class MemberHomeController extends BaseApiController
{
    public function show(TenantContext $context): MemberHomeResource|JsonResponse
    {
        return $this->handle(function () use ($context): MemberHomeResource {
            Gate::authorize('viewOwn', Member::class);

            // Le dossier de l'accès actif, cherché dans son centre (scope BelongsToOrganization).
            $member = Member::query()->with('currentRadiation')->findOrFail($context->memberId());

            return new MemberHomeResource($member, $context->organization(), $context->membership());
        }, action: 'member.home');
    }
}
