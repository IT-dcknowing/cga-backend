<?php

namespace App\Domain\Referentials\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Referentials\Contracts\Referential;
use App\Domain\Referentials\Enums\DataStatus;
use Carbon\CarbonInterface;
use Database\Factories\TaxOfficeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Centre des impôts (CDI) d'une direction régionale (F-REF-02).
 *
 * @property string $id
 * @property string $regional_directorate_id
 * @property string $code
 * @property string $name
 * @property bool $active
 * @property DataStatus $data_status
 * @property CarbonInterface|null $validated_at
 * @property-read RegionalDirectorate $regionalDirectorate
 */
class TaxOffice extends Model implements Referential
{
    /** @use HasFactory<TaxOfficeFactory> */
    use Auditable, HasFactory, HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = ['regional_directorate_id', 'code', 'name', 'active'];

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
        return ['organizations' => 'tax_office_id'];
    }

    /**
     * @return BelongsTo<RegionalDirectorate, $this>
     */
    public function regionalDirectorate(): BelongsTo
    {
        return $this->belongsTo(RegionalDirectorate::class);
    }

    protected static function newFactory(): TaxOfficeFactory
    {
        return TaxOfficeFactory::new();
    }
}
