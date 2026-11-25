<?php

namespace App\Domain\Dues\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Dues\Enums\DuesStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Payments\Models\Invoice;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonInterface;
use Database\Factories\NetworkDueFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cotisation annuelle d'un CGA au CNCGA (F-COT-01, PRD M14) : montant de l'année, prorata semestriel
 * (RG-COT-02), dû, réglé (paiements confirmés), date butoir, statut (RG-COT-04), relances. Cloisonnée
 * au CGA débiteur ; le CNCGA les lit toutes par un contournement nommé (seule exception métier,
 * 05-BACKEND-SCHEMA §10).
 *
 * @property string $id
 * @property string $organization_id
 * @property int $year
 * @property int $base_amount
 * @property string $prorata_ratio
 * @property int $amount_due
 * @property int $amount_paid
 * @property CarbonInterface $deadline
 * @property DuesStatus $status
 * @property CarbonInterface|null $last_reminder_at
 * @property int $reminder_count
 * @property string|null $invoice_id
 * @property-read Organization $organization
 * @property-read Invoice|null $invoice
 */
class NetworkDue extends Model
{
    /** @use HasFactory<NetworkDueFactory> */
    use Auditable, BelongsToOrganization, HasFactory, HasUlids;

    /**
     * Écrit par les Actions des cotisations seulement.
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
            'year' => 'integer',
            'base_amount' => 'integer',
            'amount_due' => 'integer',
            'amount_paid' => 'integer',
            'deadline' => 'immutable_date',
            'status' => DuesStatus::class,
            'last_reminder_at' => 'immutable_datetime',
            'reminder_count' => 'integer',
        ];
    }

    public function balance(): int
    {
        return max(0, $this->amount_due - $this->amount_paid);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Facture de cotisation certifiée FNE (étape 6.9).
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return HasMany<NetworkDuePayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(NetworkDuePayment::class);
    }

    protected static function newFactory(): NetworkDueFactory
    {
        return NetworkDueFactory::new();
    }
}
