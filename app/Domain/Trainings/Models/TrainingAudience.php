<?php

namespace App\Domain\Trainings\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Trainings\Enums\AudienceType;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Public ciblé par une formation (F-FOR-01) : un type, le régime pour « adhérents d'un régime », l'ancienneté
 * en mois pour « nouveaux adhérents » (trainings.new_member_months à la création). Cloisonné à l'organisateur.
 *
 * @property int $id
 * @property string $organization_id
 * @property string $training_id
 * @property AudienceType $audience
 * @property TaxRegime|null $regime
 * @property int|null $new_member_months
 */
class TrainingAudience extends Model
{
    use Auditable, BelongsToOrganization;

    public $timestamps = false;

    /**
     * Écrit par les Actions des formations seulement.
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
            'audience' => AudienceType::class,
            'regime' => TaxRegime::class,
            'new_member_months' => 'integer',
        ];
    }
}
