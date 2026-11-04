<?php

namespace App\Domain\Statistics\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Agrégat d'un CGA abonné pour un jour (05-BACKEND-SCHEMA §11, 02-TRD §9) : portefeuille CGA seulement
 * (RG-STA-05), aucune donnée nominative. Stocks arrêtés le jour, flux du jour. Écrit par
 * RefreshOrganizationStats seulement ; cloisonné au CGA, que le CNCGA lit par un contournement nommé.
 *
 * @property string $organization_id
 * @property CarbonInterface $day
 * @property int $active_members
 * @property int $members_rme
 * @property int $members_tee
 * @property int $members_rsi
 * @property int $new_members
 * @property int $radiations
 * @property int $fees_due
 * @property int $fees_paid
 * @property int $tax_paid_total
 * @property array<string, int> $tax_paid_by_nature
 * @property int $declared_revenue
 * @property array<string, int> $members_by_sector
 * @property array<string, int> $members_by_commune
 * @property int $concerns_opened
 * @property int $concerns_closed
 * @property int $concerns_responded
 * @property string|null $concerns_avg_response_hours
 * @property CarbonInterface $computed_at
 */
class StatOrgDaily extends Model
{
    use BelongsToOrganization;

    protected $table = 'stat_org_daily';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'day';

    protected $keyType = 'string';

    /**
     * Écrit par RefreshOrganizationStats seulement.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * Colonnes de flux (sommées sur une période) et de stock (valeur du dernier jour de la période).
     */
    public const FLOWS = ['new_members', 'radiations', 'fees_due', 'fees_paid', 'tax_paid_total', 'concerns_opened', 'concerns_closed', 'concerns_responded'];

    public const STOCKS = ['active_members', 'members_rme', 'members_tee', 'members_rsi', 'declared_revenue'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day' => 'immutable_date',
            'active_members' => 'integer',
            'members_rme' => 'integer',
            'members_tee' => 'integer',
            'members_rsi' => 'integer',
            'new_members' => 'integer',
            'radiations' => 'integer',
            'fees_due' => 'integer',
            'fees_paid' => 'integer',
            'tax_paid_total' => 'integer',
            'tax_paid_by_nature' => 'array',
            'declared_revenue' => 'integer',
            'members_by_sector' => 'array',
            'members_by_commune' => 'array',
            'concerns_opened' => 'integer',
            'concerns_closed' => 'integer',
            'concerns_responded' => 'integer',
            'computed_at' => 'immutable_datetime',
        ];
    }
}
