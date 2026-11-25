<?php

namespace App\Domain\Statistics\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Mois du réseau (vue matérialisée stat_network_monthly, 05-BACKEND-SCHEMA §11) : CGA inscrits au registre et
 * abonnés à la fin du mois, cotisations attendues et recouvrées, rapports attendus et transmis. Statistiques
 * institutionnelles sur tous les CGA (RG-STA-02), sans donnée nominative ; lecture seule, recalculée par
 * stats:refresh.
 *
 * @property CarbonInterface $month
 * @property CarbonInterface $month_end
 * @property int $total_cgas
 * @property int $subscribed_cgas
 * @property int $dues_expected
 * @property int $dues_collected
 * @property int $dues_collected_year_to_date
 * @property int $dues_paid_cgas
 * @property int $reports_due
 * @property int $reports_transmitted
 * @property int $reports_transmitted_late
 * @property CarbonInterface $computed_at
 */
class StatNetworkMonthly extends Model
{
    protected $table = 'stat_network_monthly';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'month';

    protected $keyType = 'string';

    /**
     * Vue en lecture seule.
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
            'month' => 'immutable_date',
            'month_end' => 'immutable_date',
            'total_cgas' => 'integer',
            'subscribed_cgas' => 'integer',
            'dues_expected' => 'integer',
            'dues_collected' => 'integer',
            'dues_collected_year_to_date' => 'integer',
            'dues_paid_cgas' => 'integer',
            'reports_due' => 'integer',
            'reports_transmitted' => 'integer',
            'reports_transmitted_late' => 'integer',
            'computed_at' => 'immutable_datetime',
        ];
    }
}
