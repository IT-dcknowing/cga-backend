<?php

namespace App\Domain\Dues\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Documents\Models\File;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Dues\Enums\DuesPaymentSource;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Paiement d'une cotisation (F-COT-03, 04) : déclaré par le CGA avec sa preuve (« à confirmer ») ou
 * saisi par le CNCGA ; confirmé avec un reçu numéroté, ou rejeté avec son motif. Cloisonné au CGA
 * débiteur.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $network_due_id
 * @property int $amount
 * @property CarbonInterface $paid_on
 * @property PaymentMethod $method
 * @property string|null $reference
 * @property string|null $proof_file_id
 * @property DuesPaymentSource $source
 * @property PaymentStatus $status
 * @property string|null $rejection_reason
 * @property string|null $confirmed_by
 * @property CarbonInterface|null $confirmed_at
 * @property string|null $receipt_number
 * @property string|null $receipt_document_id
 * @property string|null $created_by
 * @property-read NetworkDue $due
 * @property-read File|null $proof
 * @property-read GeneratedDocument|null $receipt
 */
class NetworkDuePayment extends Model
{
    use Auditable, BelongsToOrganization, HasUlids;

    /**
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
            'paid_on' => 'immutable_date',
            'method' => PaymentMethod::class,
            'source' => DuesPaymentSource::class,
            'status' => PaymentStatus::class,
            'confirmed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<NetworkDue, $this>
     */
    public function due(): BelongsTo
    {
        return $this->belongsTo(NetworkDue::class, 'network_due_id');
    }

    /**
     * @return BelongsTo<File, $this>
     */
    public function proof(): BelongsTo
    {
        return $this->belongsTo(File::class, 'proof_file_id');
    }

    /**
     * @return BelongsTo<GeneratedDocument, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class, 'receipt_document_id');
    }
}
