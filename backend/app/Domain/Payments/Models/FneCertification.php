<?php

namespace App\Domain\Payments\Models;

use App\Domain\Payments\Enums\CertificationAttemptStatus;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Échange avec le service de certification FNE (RG-FNE-04) : n° de l'essai, demande et réponse
 * horodatées, issue, référence du service, message (jamais de donnée sensible), prochain essai.
 *
 * @property int $id
 * @property string $organization_id
 * @property string $invoice_id
 * @property int $attempt
 * @property CarbonInterface $requested_at
 * @property CarbonInterface|null $responded_at
 * @property CertificationAttemptStatus $status
 * @property string|null $provider_reference
 * @property string|null $message
 * @property CarbonInterface|null $next_retry_at
 */
class FneCertification extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    /**
     * Écrit par CertifyInvoice seulement.
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
            'status' => CertificationAttemptStatus::class,
            'requested_at' => 'immutable_datetime',
            'responded_at' => 'immutable_datetime',
            'next_retry_at' => 'immutable_datetime',
        ];
    }
}
