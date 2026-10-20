<?php

namespace App\Domain\Referentials\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Referentials\Contracts\Referential;
use App\Domain\Referentials\Enums\DataStatus;
use Carbon\CarbonInterface;
use Database\Factories\CommuneFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Commune, rattachée ou non à une direction régionale (F-REF-02).
 *
 * @property string $id
 * @property string $name
 * @property string|null $regional_directorate_id
 * @property DataStatus $data_status
 * @property CarbonInterface|null $validated_at
 * @property-read RegionalDirectorate|null $regionalDirectorate
 */
class Commune extends Model implements Referential
{
    /** @use HasFactory<CommuneFactory> */
    use Auditable, HasFactory, HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'regional_directorate_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data_status' => DataStatus::class,
            'validated_at' => 'immutable_datetime',
        ];
    }

    public static function referencedBy(): array
    {
        return ['organizations' => 'commune_id'];
    }

    /**
     * @return BelongsTo<RegionalDirectorate, $this>
     */
    public function regionalDirectorate(): BelongsTo
    {
        return $this->belongsTo(RegionalDirectorate::class);
    }

    protected static function newFactory(): CommuneFactory
    {
        return CommuneFactory::new();
    }
}
