<?php

namespace App\Domain\Members\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Documents\Models\File;
use App\Domain\Members\Enums\PieceStatus;
use App\Domain\Members\Enums\PieceType;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pièce justificative attendue d'un dossier (F-PJ-01) : statut (RG-PJ-01), fichier déposé, dates.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $member_id
 * @property PieceType $piece_type
 * @property PieceStatus $status
 * @property string|null $file_id
 * @property CarbonInterface|null $received_at
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $last_reminder_at
 * @property-read Member $member
 * @property-read File|null $file
 */
class MemberPiece extends Model
{
    use Auditable, BelongsToOrganization, HasUlids;

    /**
     * Écrit par les Actions du dossier seulement.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'piece_type' => PieceType::class,
            'status' => PieceStatus::class,
            'received_at' => 'immutable_date',
            'expires_at' => 'immutable_date',
            'last_reminder_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * @return BelongsTo<File, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
