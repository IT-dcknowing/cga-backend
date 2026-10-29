<?php

namespace App\Domain\Obligations\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Obligations\Enums\CenterObligationKind;
use App\Domain\Obligations\Enums\CenterObligationStatus;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonInterface;
use Database\Factories\CenterObligationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Obligation propre du CGA (F-OBL-07) : ses impôts, sa CNPS, le renouvellement de son agrément, ses
 * SMT… suivis dans « Gestion interne ».
 *
 * @property string $id
 * @property string $organization_id
 * @property string $label
 * @property CenterObligationKind $kind
 * @property CarbonInterface $due_date
 * @property int|null $amount
 * @property CenterObligationStatus $status
 * @property string|null $notes
 * @property CarbonInterface|null $reminder_sent_at
 */
class CenterObligation extends Model
{
    /** @use HasFactory<CenterObligationFactory> */
    use Auditable, BelongsToOrganization, HasFactory, HasUlids;

    /**
     * Écrit par les Actions des obligations seulement.
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
            'kind' => CenterObligationKind::class,
            'status' => CenterObligationStatus::class,
            'due_date' => 'immutable_date',
            'reminder_sent_at' => 'immutable_datetime',
            'amount' => 'integer',
        ];
    }

    protected static function newFactory(): CenterObligationFactory
    {
        return CenterObligationFactory::new();
    }
}
