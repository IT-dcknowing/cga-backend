<?php

namespace App\Domain\Members\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Members\Enums\FeeKind;
use App\Domain\Members\Enums\FeesPeriodicity;
use App\Domain\Members\Enums\TaxRegime;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Barème du CGA (F-PAY-01) : droit d'adhésion, honoraires par régime (RME, TEE), à partir d'une date ;
 * propose les montants à l'inscription.
 *
 * @property string $id
 * @property string $organization_id
 * @property FeeKind $kind
 * @property TaxRegime|null $regime
 * @property int $amount
 * @property FeesPeriodicity|null $periodicity
 * @property CarbonInterface $valid_from
 */
class FeeSchedule extends Model
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
            'kind' => FeeKind::class,
            'regime' => TaxRegime::class,
            'periodicity' => FeesPeriodicity::class,
            'amount' => 'integer',
            'valid_from' => 'immutable_date',
        ];
    }
}
