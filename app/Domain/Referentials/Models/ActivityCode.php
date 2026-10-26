<?php

namespace App\Domain\Referentials\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Referentials\Contracts\Referential;
use App\Domain\Referentials\Enums\DataStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Code d'activité de la nomenclature DGI (05-BACKEND-SCHEMA §4, RG-REF-02), rattaché à un secteur ;
 * chargé quand la nomenclature sera fournie, en données provisoires (RG-DATA-02).
 *
 * @property string $id
 * @property string $code
 * @property string $label
 * @property string|null $sector_id
 * @property bool $active
 * @property DataStatus $data_status
 * @property CarbonInterface|null $validated_at
 * @property-read Sector|null $sector
 */
class ActivityCode extends Model implements Referential
{
    use Auditable, HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = ['code', 'label', 'sector_id', 'active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'data_status' => DataStatus::class,
            'validated_at' => 'immutable_datetime',
        ];
    }

    public static function referencedBy(): array
    {
        return ['members' => 'activity_code_id'];
    }

    /**
     * @return BelongsTo<Sector, $this>
     */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }
}
