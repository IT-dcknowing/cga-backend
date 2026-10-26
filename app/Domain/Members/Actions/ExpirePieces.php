<?php

namespace App\Domain\Members\Actions;

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\PieceStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\MemberPiece;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;

/**
 * Pièces datées arrivées à échéance (RG-PJ-01) : une pièce reçue dont la date de validité est passée
 * devient « expirée » et son dossier validé repasse « pièces en attente » (RG-PJ-02). Idempotent, appelé
 * chaque nuit par statuses:refresh, pour tous les CGA (contournement journalisé).
 */
class ExpirePieces
{
    public function __construct(private readonly CreateExpectedPieces $pieces) {}

    /**
     * @return int nombre de pièces expirées
     */
    public function execute(): int
    {
        return Tenancy::bypass(fn (): int => DB::transaction(function (): int {
            $expired = MemberPiece::query()
                ->where('status', PieceStatus::Received)
                ->whereNotNull('expires_at')
                ->whereDate('expires_at', '<', today())
                ->lockForUpdate()
                ->get();

            foreach ($expired as $piece) {
                $piece->status = PieceStatus::Expired;
                $piece->save();
            }
            Member::query()->whereIn('id', $expired->pluck('member_id')->unique())->where('status', '!=', MemberStatus::Draft)->get()
                ->each(fn (Member $member) => $this->pieces->refreshStatus($member));

            return $expired->count();
        }), 'Tâche statuses:refresh : pièces datées expirées dans tous les CGA (RG-PJ-01).');
    }
}
