<?php

namespace App\Domain\Members\Http\Controllers;

use App\Domain\Members\Actions\CreateExpectedPieces;
use App\Domain\Members\Actions\StoreMemberPiece;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\PieceStatus;
use App\Domain\Members\Enums\PieceType;
use App\Domain\Members\Http\Requests\StorePieceRequest;
use App\Domain\Members\Http\Resources\PieceResource;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\MemberPiece;
use App\Support\Http\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/cga/members/{member}/pieces : onglet « Pièces » du dossier (F-PJ-01) — pièces attendues et
 * leur statut, dépôt d'une pièce.
 */
class PieceController extends BaseApiController
{
    public function index(Member $member, CreateExpectedPieces $expected): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($member, $expected): AnonymousResourceCollection {
            Gate::authorize('view', $member);

            return PieceResource::collection($this->pieces($member, $expected));
        }, action: 'members.pieces.index');
    }

    public function store(StorePieceRequest $request, Member $member, PieceType $pieceType, StoreMemberPiece $store): JsonResponse
    {
        return $this->handle(function () use ($request, $member, $pieceType, $store): JsonResponse {
            $piece = $store->execute($member, $pieceType, $request->upload(), $request->expiresAt(), $this->currentUser());
            $this->logSubject($member);

            return (new PieceResource($piece->load('file'), true))->response()->setStatusCode(201);
        }, action: 'members.pieces.store');
    }

    /**
     * Les pièces attendues du dossier, dans l'ordre du F-PJ-01, puis celles devenues sans objet.
     *
     * @return list<PieceResource>
     */
    private function pieces(Member $member, CreateExpectedPieces $expected): array
    {
        $canUpload = Gate::allows('managePieces', $member) && ! in_array($member->status, [MemberStatus::Radiated, MemberStatus::Closed], true);
        $stored = MemberPiece::query()->with('file')->where('member_id', $member->id)->get()->keyBy(static fn (MemberPiece $piece): string => $piece->piece_type->value);
        $types = $expected->types($member);
        $types = [...$types, ...array_values(array_filter(PieceType::cases(), static fn (PieceType $type): bool => ! in_array($type, $types, true) && $stored->has($type->value)))];

        return array_map(static function (PieceType $type) use ($stored, $member, $canUpload): PieceResource {
            $piece = $stored->get($type->value);
            if (! $piece instanceof MemberPiece) {
                $piece = new MemberPiece;
                $piece->member_id = $member->id;
                $piece->piece_type = $type;
                $piece->status = PieceStatus::Expected;
                $piece->setRelation('file', null);
            }

            return new PieceResource($piece, $canUpload && $piece->status !== PieceStatus::NotApplicable);
        }, $types);
    }
}
