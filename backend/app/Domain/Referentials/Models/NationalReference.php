<?php

namespace App\Domain\Referentials\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Referentials\Contracts\Referential;
use App\Domain\Referentials\Enums\DataStatus;
use Carbon\CarbonInterface;
use Database\Factories\NationalReferenceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Références nationales d'une année (F-REF-04) : recettes fiscales intérieures et PIB nominal, en
 * FCFA, avec leur source.
 *
 * @property string $id
 * @property int $year
 * @property int|null $domestic_tax_revenue
 * @property int|null $nominal_gdp
 * @property string|null $tax_revenue_source
 * @property string|null $gdp_source
 * @property string|null $updated_by
 * @property DataStatus $data_status
 * @property CarbonInterface|null $validated_at
 */
class NationalReference extends Model implements Referential
{
    /** @use HasFactory<NationalReferenceFactory> */
    use Auditable, HasFactory, HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = ['year', 'domestic_tax_revenue', 'nominal_gdp', 'tax_revenue_source', 'gdp_source'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'domestic_tax_revenue' => 'integer',
            'nominal_gdp' => 'integer',
            'data_status' => DataStatus::class,
            'validated_at' => 'immutable_datetime',
        ];
    }

    public static function referencedBy(): array
    {
        return [];
    }

    protected static function newFactory(): NationalReferenceFactory
    {
        return NationalReferenceFactory::new();
    }
}
