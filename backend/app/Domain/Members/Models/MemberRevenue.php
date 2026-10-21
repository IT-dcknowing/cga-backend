<?php

namespace App\Domain\Members\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Members\Enums\RevenueKind;
use App\Domain\Members\Enums\RevenueSource;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Chiffre d'affaires d'un dossier pour une année, prévisionnel ou réalisé, avec son origine
 * (RG-DATA-06 : une valeur Compta Flow fait foi).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $member_id
 * @property int $year
 * @property int $amount
 * @property RevenueKind $kind
 * @property RevenueSource $source
 * @property-read Member $member
 */
class MemberRevenue extends Model
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
            'year' => 'integer',
            'amount' => 'integer',
            'kind' => RevenueKind::class,
            'source' => RevenueSource::class,
            'synced_at' => 'immutable_datetime',
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
