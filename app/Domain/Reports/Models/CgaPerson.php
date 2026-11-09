<?php

namespace App\Domain\Reports\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Reports\Enums\CgaPersonKind;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonInterface;
use Database\Factories\CgaPersonFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Dirigeant, salarié permanent ou personnel occasionnel du CGA (fiche permanente, F-RPT-01) : sections
 * 1 et 2 du rapport trimestriel. Une personne qui quitte le centre est désactivée avec sa date de fin,
 * jamais effacée.
 *
 * @property string $id
 * @property string $organization_id
 * @property CgaPersonKind $kind
 * @property string $full_name
 * @property string|null $nationality
 * @property string|null $profession
 * @property string|null $diplomas
 * @property string|null $function_in_cga
 * @property CarbonInterface|null $start_date
 * @property string|null $practice_structure
 * @property string|null $practice_function
 * @property int|null $practice_years
 * @property bool|null $cnps_declared
 * @property bool $active
 * @property CarbonInterface|null $end_date
 */
class CgaPerson extends Model
{
    /** @use HasFactory<CgaPersonFactory> */
    use Auditable, BelongsToOrganization, HasFactory, HasUlids;

    protected $table = 'cga_people';

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
            'kind' => CgaPersonKind::class,
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'practice_years' => 'integer',
            'cnps_declared' => 'boolean',
            'active' => 'boolean',
        ];
    }

    protected static function newFactory(): CgaPersonFactory
    {
        return CgaPersonFactory::new();
    }
}
