<?php

namespace App\Domain\Payments\Models;

use App\Domain\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Part d'un paiement affectée à une somme due (F-PAY-03). Lue par le paiement ou la somme due, qui
 * portent le cloisonnement.
 *
 * @property int $id
 * @property string $payment_id
 * @property string $charge_id
 * @property int $amount
 * @property-read MemberPayment $payment
 * @property-read MemberCharge $charge
 */
class PaymentAllocation extends Model
{
    use Auditable;

    protected $table = 'member_payment_allocations';

    public $timestamps = false;

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
            'amount' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<MemberPayment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(MemberPayment::class, 'payment_id');
    }

    /**
     * @return BelongsTo<MemberCharge, $this>
     */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(MemberCharge::class, 'charge_id');
    }
}
