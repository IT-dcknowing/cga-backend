<?php

namespace App\Domain\Payments\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Members\Models\Member;
use App\Domain\Payments\Enums\ChargeKind;
use App\Domain\Payments\Enums\ChargeStatus;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonInterface;
use Database\Factories\MemberChargeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Somme due par l'adhérent à son CGA (F-PAY-02) : droit d'adhésion, échéance d'honoraires d'une
 * période, formation ; hors taxe, taux et TVA, montant dû toutes taxes comprises ; facture qui la porte
 * (F-PAY-05) ; montant payé maintenu par les Actions de paiement, état (à payer, payée en
 * partie ou en totalité, impayée depuis…, annulée).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $member_id
 * @property ChargeKind $kind
 * @property CarbonInterface|null $period_start
 * @property CarbonInterface|null $period_end
 * @property CarbonInterface $due_date
 * @property int $amount_excl_tax
 * @property float $tax_rate
 * @property int $tax_amount
 * @property int $amount montant dû, toutes taxes comprises
 * @property int $amount_paid
 * @property ChargeStatus $status
 * @property CarbonInterface|null $overdue_since
 * @property string|null $training_registration_id
 * @property string|null $invoice_id
 * @property-read Member $member
 */
class MemberCharge extends Model
{
    /** @use HasFactory<MemberChargeFactory> */
    use Auditable, BelongsToOrganization, HasFactory, HasUlids;

    /**
     * Écrit par les Actions des paiements seulement.
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
            'kind' => ChargeKind::class,
            'status' => ChargeStatus::class,
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'due_date' => 'immutable_date',
            'overdue_since' => 'immutable_date',
            'amount_excl_tax' => 'integer',
            'tax_rate' => 'float',
            'tax_amount' => 'integer',
            'amount' => 'integer',
            'amount_paid' => 'integer',
        ];
    }

    /**
     * Reste à payer.
     */
    public function balance(): int
    {
        return max(0, $this->amount - $this->amount_paid);
    }

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'charge_id');
    }

    protected static function newFactory(): MemberChargeFactory
    {
        return MemberChargeFactory::new();
    }
}
