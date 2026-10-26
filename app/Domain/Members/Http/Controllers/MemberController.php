<?php

namespace App\Domain\Members\Http\Controllers;

use App\Domain\Members\Actions\CreateMemberDraft;
use App\Domain\Members\Actions\DeleteDraft;
use App\Domain\Members\Actions\UpdateMember;
use App\Domain\Members\Actions\ValidateMembership;
use App\Domain\Members\Http\Requests\ListMembersRequest;
use App\Domain\Members\Http\Requests\NccCheckRequest;
use App\Domain\Members\Http\Requests\StoreMemberRequest;
use App\Domain\Members\Http\Requests\UpdateMemberRequest;
use App\Domain\Members\Http\Requests\ValidateMembershipRequest;
use App\Domain\Members\Http\Resources\MemberResource;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Support\MembershipExclusivity;
use App\Domain\Members\Support\Ncc;
use App\Support\Config\SettingsService;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * /api/v1/cga/members : dossiers des adhérents du centre (F-ADH-01 à 04) — liste par portefeuille,
 * inscription en brouillon, validation de l'adhésion, fiche, modification, suppression d'un brouillon,
 * contrôle du NCC.
 */
class MemberController extends BaseApiController
{
    /**
     * Relations montrées avec la fiche.
     */
    public const WITH = ['referrer', 'taxOffice', 'sector', 'commune', 'activityCode', 'revenues', 'currentRadiation.proof', 'overdueFees', 'currentAttestationBlock'];

    public function __construct(private readonly TenantContext $context) {}

    public function index(ListMembersRequest $request, SettingsService $settings): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($request, $settings): AnonymousResourceCollection {
            foreach ($request->portfolios() as $portfolio) {
                Gate::authorize('viewPortfolio', [Member::class, $portfolio]);
            }

            $members = QueryBuilder::for(Member::query()->with(self::WITH), $request->listQuery())
                ->allowedFilters(...$request->queryFilters())
                ->allowedSorts(...$request->sorts())
                ->defaultSort('company_name')
                ->paginate($request->perPage($settings), ['*'], 'page', $request->page());

            return MemberResource::collection($members);
        }, action: 'members.index');
    }

    public function store(StoreMemberRequest $request, CreateMemberDraft $create): MemberResource|JsonResponse
    {
        return $this->handle(function () use ($request, $create): MemberResource {
            Gate::authorize('create', [Member::class, $request->portfolio()]);
            $member = $create->execute($this->context->organizationId(), $request->portfolio(), $request->fields(), $request->revenue(), (bool) $request->contractConfirmed(), $this->currentUser(), $request->transferId());
            $this->logSubject($member);

            return new MemberResource($member->load(self::WITH));
        }, action: 'members.store');
    }

    public function show(Member $member): MemberResource|JsonResponse
    {
        return $this->handle(function () use ($member): MemberResource {
            Gate::authorize('view', $member);

            return new MemberResource($member->load(self::WITH));
        }, action: 'members.show');
    }

    public function update(UpdateMemberRequest $request, Member $member, UpdateMember $update): MemberResource|JsonResponse
    {
        return $this->handle(function () use ($request, $member, $update): MemberResource {
            $member = $update->execute($member, $request->fields(), $request->revenue(), $request->contractConfirmed(), $request->justification(), $this->currentUser());

            return new MemberResource($member->load(self::WITH));
        }, action: 'members.update');
    }

    public function validate(ValidateMembershipRequest $request, Member $member, ValidateMembership $validate): MemberResource|JsonResponse
    {
        return $this->handle(function () use ($request, $member, $validate): MemberResource {
            $member = $validate->execute($member, $request->exclusivityConfirmed(), $this->currentUser());

            return new MemberResource($member->load(self::WITH));
        }, action: 'members.validate');
    }

    public function destroy(Member $member, DeleteDraft $delete): Response|JsonResponse
    {
        return $this->handle(function () use ($member, $delete): Response {
            Gate::authorize('delete', $member);
            $delete->execute($member);

            return response()->noContent();
        }, action: 'members.destroy');
    }

    /**
     * Contrôle à la volée du NCC (F-ADH-02) : format, dossier déjà ouvert dans le centre, inscription au
     * portefeuille CGA d'un autre CGA (oui / non seulement, RG-ADH-05).
     */
    public function nccCheck(NccCheckRequest $request, MembershipExclusivity $exclusivity): JsonResponse
    {
        return $this->handle(function () use ($request, $exclusivity): JsonResponse {
            $ncc = $request->ncc();
            $valid = Ncc::isValid($ncc);
            $existing = $valid ? Member::query()->open()->where('ncc', $ncc)->first() : null;

            return response()->json(['data' => [
                'ncc' => $ncc,
                'valid' => $valid,
                'existing' => $existing === null ? null : [
                    'id' => $existing->id,
                    'company_name' => $existing->company_name,
                    'status' => $existing->status->value,
                    'portfolio' => $existing->portfolio->value,
                ],
                'enrolled_elsewhere' => $valid && $exclusivity->enrolledElsewhere($ncc, $this->context->organizationId()),
            ]]);
        }, action: 'members.ncc-check');
    }
}
