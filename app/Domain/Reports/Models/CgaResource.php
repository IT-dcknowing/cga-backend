<?php

namespace App\Domain\Reports\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Reports\Enums\CgaResourceCategory;
use App\Support\Tenancy\BelongsToOrganization;
use Database\Factories\CgaResourceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Moyens matériels du CGA, une ligne par catégorie (fiche permanente, F-RPT-01) : quantité, description,
 * besoins ; section 3 du rapport trimestriel.
 *
 * @property string $id
 * @property string $organization_id
 * @property CgaResourceCategory $category
 * @property int $quantity
 * @property string|null $description
 * @property string|null $need
 */
class CgaResource extends Model
{
    /** @use HasFactory<CgaResourceFactory> */
    use Auditable, BelongsToOrganization, HasFactory, HasUlids;

    /**
     * Écrit par les Actions de la fiche permanente seulement.
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
            'category' => CgaResourceCategory::class,
            'quantity' => 'integer',
        ];
    }

    protected static function newFactory(): CgaResourceFactory
    {
        return CgaResourceFactory::new();
    }
}
