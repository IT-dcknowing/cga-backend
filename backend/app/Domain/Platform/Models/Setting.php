<?php

namespace App\Domain\Platform\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Valeur d'un paramètre du registre config/cga.php : réseau ou plateforme sans organisation, valeur
 * propre d'un CGA avec son organisation (02-TRD §7.5). Lue et écrite par SettingsService.
 *
 * @property int $id
 * @property string|null $organization_id
 * @property string $key
 * @property mixed $value
 */
class Setting extends Model
{
    use Auditable;

    /**
     * Écrit par SettingsService seulement, après validation selon le registre.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
