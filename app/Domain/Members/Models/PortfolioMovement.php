<?php

namespace App\Domain\Members\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Members\Enums\MovementDirection;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bascule d'un dossier entre portefeuilles (RG-PTF-03 à 05) : sens, date d'effet, motif, contrat
 * (vers le CGA), radiation validée (vers le Cabinet), apporteur retiré.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $member_id
 * @property MovementDirection $direction
 * @property CarbonInterface $effective_date
 * @property string $reason
 * @property CarbonInterface|null $contract_signed_on
 * @property string|null $contract_file_id
 * @property string|null $radiation_id
 * @property string|null $previous_referrer_id
 * @property string $created_by
 * @property-read Member $member
 */
class PortfolioMovement extends Model
{
    use Auditable, BelongsToOrganization, HasUlids;

    public const UPDATED_AT = null;

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
            'direction' => MovementDirection::class,
            'effective_date' => 'immutable_date',
            'contract_signed_on' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
