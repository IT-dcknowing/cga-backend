<?php

namespace App\Domain\Members\Actions;

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\PieceStatus;
use App\Domain\Members\Enums\PieceType;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\MemberPiece;
use App\Domain\Users\Models\User;
use App\Support\Files\StorageService;
use App\Support\Http\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * « Déposer » une pièce du dossier (F-PJ-01) : la pièce passe « reçue », ou « expirée » si sa date de
 * validité est déjà passée (RG-PJ-01) ; un nouveau dépôt remplace le précédent, qui reste conservé.
 * Le contrat CGA signé devient l'exemplaire scanné du dossier (RG-PTF-08). Un brouillon peut déjà
 * recevoir ses pièces ; l'état « pièces en attente » d'un dossier validé est recalculé (RG-PJ-02).
 */
class StoreMemberPiece
{
    public function __construct(
        private readonly StorageService $storage,
        private readonly CreateExpectedPieces $pieces,
    ) {}

    /**
     * @throws BusinessRuleException member_closed, piece_not_applicable
     */
    public function execute(Member $member, PieceType $type, UploadedFile $upload, ?CarbonImmutable $expiresAt, User $actor): MemberPiece
    {
        return DB::transaction(function () use ($member, $type, $upload, $expiresAt, $actor): MemberPiece {
            $member = Member::query()->lockForUpdate()->findOrFail($member->id);
            if (in_array($member->status, [MemberStatus::Radiated, MemberStatus::Closed], true)) {
                throw new BusinessRuleException('member_closed');
            }
            if (! in_array($type, $this->pieces->types($member), true)) {
                throw new BusinessRuleException('piece_not_applicable', details: ['piece_type' => $type->value]);
            }

            $file = $this->storage->storeUpload($upload, 'pieces', $member->organization_id, $actor->id);
            $piece = MemberPiece::query()->where('member_id', $member->id)->where('piece_type', $type)->lockForUpdate()->first() ?? new MemberPiece;
            $piece->organization_id = $member->organization_id;
            $piece->member_id = $member->id;
            $piece->piece_type = $type;
            $piece->status = $expiresAt !== null && $expiresAt->lt(today()) ? PieceStatus::Expired : PieceStatus::Received;
            $piece->file_id = $file->id;
            $piece->received_at = today();
            $piece->expires_at = $expiresAt;
            $piece->save();

            if ($type === PieceType::SignedCgaContract) {
                $member->cga_contract_file_id = $file->id;
                $member->updated_by = $actor->id;
                $member->save();
            }
            if ($member->status !== MemberStatus::Draft) {
                $this->pieces->refreshStatus($member);
            }

            return $piece->refresh();
        });
    }
}
