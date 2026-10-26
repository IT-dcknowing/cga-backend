<?php

namespace App\Domain\Members\Actions;

use App\Domain\Members\Enums\EntityType;
use App\Domain\Members\Enums\PiecesStatus;
use App\Domain\Members\Enums\PieceStatus;
use App\Domain\Members\Enums\PieceType;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\MemberPiece;

/**
 * Pièces attendues d'un dossier validé (F-PJ-01) : RCCM, DFE, pièce d'identité du dirigeant, plan de
 * localisation, statuts pour une personne morale, contrat CGA signé au portefeuille CGA (reçu si
 * l'exemplaire scanné est déjà joint, RG-PTF-08). L'état « pièces en attente » du dossier en découle
 * (RG-PJ-02).
 */
class CreateExpectedPieces
{
    public function execute(Member $member): void
    {
        $existing = MemberPiece::query()->where('member_id', $member->id)->pluck('piece_type')->map(static fn (PieceType $type): string => $type->value)->all();
        foreach ($this->types($member) as $type) {
            if (in_array($type->value, $existing, true)) {
                continue;
            }

            $piece = new MemberPiece;
            $piece->member_id = $member->id;
            $piece->organization_id = $member->organization_id;
            $piece->piece_type = $type;
            $contract = $type === PieceType::SignedCgaContract && $member->cga_contract_file_id !== null;
            $piece->status = $contract ? PieceStatus::Received : PieceStatus::Expected;
            $piece->file_id = $contract ? $member->cga_contract_file_id : null;
            $piece->received_at = $contract ? today() : null;
            $piece->save();
        }

        $this->refreshStatus($member);
    }

    /**
     * Pièces attendues pour ce dossier (F-PJ-01) : statuts pour une personne morale, contrat CGA signé au
     * portefeuille CGA.
     *
     * @return list<PieceType>
     */
    public function types(Member $member): array
    {
        $types = [PieceType::Rccm, PieceType::Dfe, PieceType::IdDocument, PieceType::LocationPlan];
        if ($member->entity_type === EntityType::Company) {
            $types[] = PieceType::Statutes;
        }
        if ($member->portfolio === Portfolio::Cga) {
            $types[] = PieceType::SignedCgaContract;
        }

        return $types;
    }

    /**
     * État des pièces du dossier : complètes quand aucune pièce n'est attendue ni expirée.
     */
    public function refreshStatus(Member $member): void
    {
        $pending = MemberPiece::query()->where('member_id', $member->id)->whereIn('status', [PieceStatus::Expected, PieceStatus::Expired])->exists();
        $member->pieces_status = $pending ? PiecesStatus::Pending : PiecesStatus::Complete;
        $member->save();
    }
}
