<?php

namespace App\Domain\Referentials\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Referentials\Contracts\Referential;
use App\Domain\Referentials\Enums\DataStatus;
use Carbon\CarbonInterface;
use Database\Factories\RegionalDirectorateFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Direction régionale des impôts (F-REF-02).
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string|null $geo_key
 * @property bool $active
 * @property DataStatus $data_status
 * @property CarbonInterface|null $validated_at
 */
class RegionalDirectorate extends Model implements Referential
{
    /** @use HasFactory<RegionalDirectorateFactory> */
    use Auditable, HasFactory, HasUlids;

    /**
     * Le statut des données (provisoire, validée) est fixé par la validation du CNCGA (étape 1.10).
     *
     * @var list<string>
     */
    protected $fillable = ['code', 'name', 'geo_key', 'active'];

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
        return [
            'tax_offices' => 'regional_directorate_id',
            'communes' => 'regional_directorate_id',
            'organizations' => 'regional_directorate_id',
        ];
    }

    /**
     * @return HasMany<TaxOffice, $this>
     */
    public function taxOffices(): HasMany
    {
        return $this->hasMany(TaxOffice::class);
    }

    protected static function newFactory(): RegionalDirectorateFactory
    {
        return RegionalDirectorateFactory::new();
    }
}
