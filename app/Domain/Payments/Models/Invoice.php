<?php

namespace App\Domain\Payments\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Documents\Models\File;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Members\Models\Member;
use App\Domain\Payments\Enums\CertificationAttemptStatus;
use App\Domain\Payments\Enums\CertificationMode;
use App\Domain\Payments\Enums\InvoiceStatus;
use App\Domain\Payments\Enums\InvoiceType;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * Facture ou avoir émis par une organisation (F-PAY-05, F-PAY-06) : client (adhérent, ou CGA pour une
 * cotisation), n° FAC, lignes et montants calculés par le serveur, statut de certification FNE, éléments
 * renvoyés par le service de certification (ou saisis en mode manuel, RG-FNE-03), PDF remis au client.
 * Une facture certifiée est immuable (RG-FNE-01) : une correction passe par un avoir.
 *
 * @property string $id
 * @property string $organization_id
 * @property InvoiceType $type
 * @property string|null $original_invoice_id
 * @property string|null $member_id
 * @property string|null $billed_organization_id
 * @property string $number
 * @property CarbonInterface $issue_date
 * @property list<array{charge_id: string|null, label: string, quantity: int, unit_amount: int, tax_rate: float, amount: int}> $lines
 * @property int $amount_excl_tax
 * @property int $tax_amount
 * @property int $amount_incl_tax
 * @property InvoiceStatus $status
 * @property CertificationMode $certification_mode
 * @property string|null $fne_number
 * @property string|null $fne_qr_payload
 * @property string|null $fne_stamp
 * @property CarbonInterface|null $certified_at
 * @property string|null $rejection_reason
 * @property string|null $certified_file_id
 * @property string|null $document_id
 * @property string|null $cancel_reason
 * @property string $created_by
 * @property CarbonInterface|null $created_at
 * @property-read Member|null $member
 * @property-read Invoice|null $original
 * @property-read Invoice|null $creditNote
 * @property-read Collection<int, FneCertification> $certifications
 * @property-read Collection<int, MemberCharge> $charges
 * @property-read GeneratedDocument|null $document
 * @property-read File|null $certifiedFile
 */
class Invoice extends Model
{
    use Auditable, BelongsToOrganization, HasUlids;

    /**
     * Écrit par les Actions de facturation seulement.
     *
     * @var list<string>
     */
    protected $fillable = [];

    protected static function booted(): void
    {
        // RG-FNE-01 : une facture certifiée n'est plus modifiable, sinon par son PDF remis (produit
        // après la certification).
        static::updating(static function (Invoice $invoice): void {
            if ($invoice->getOriginal('status') === InvoiceStatus::Certified && array_diff(array_keys($invoice->getDirty()), ['document_id', 'updated_at']) !== []) {
                throw new LogicException("La facture certifiée {$invoice->number} n'est plus modifiable : émettez un avoir.");
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => InvoiceType::class,
            'status' => InvoiceStatus::class,
            'certification_mode' => CertificationMode::class,
            'issue_date' => 'immutable_date',
            'lines' => 'array',
            'amount_excl_tax' => 'integer',
            'tax_amount' => 'integer',
            'amount_incl_tax' => 'integer',
            'certified_at' => 'immutable_datetime',
        ];
    }

    /**
     * Facture en attente dont le service est resté indisponible jusqu'au dernier essai (RG-FNE-04) :
     * plus aucun essai n'est programmé, le gérant la soumet de nouveau ou l'annule.
     */
    public function certificationStalled(): bool
    {
        if ($this->status !== InvoiceStatus::PendingCertification || $this->certification_mode === CertificationMode::Manual) {
            return false;
        }
        $last = FneCertification::query()->where('invoice_id', $this->id)->orderByDesc('attempt')->first();

        return $last !== null && $last->status === CertificationAttemptStatus::Error && $last->next_retry_at === null;
    }

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * Facture annulée par cet avoir.
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_invoice_id');
    }

    /**
     * Avoir en cours ou certifié de cette facture (avoir total).
     *
     * @return HasOne<Invoice, $this>
     */
    public function creditNote(): HasOne
    {
        return $this->hasOne(self::class, 'original_invoice_id')->where('status', '<>', InvoiceStatus::Cancelled);
    }

    /**
     * @return HasMany<MemberCharge, $this>
     */
    public function charges(): HasMany
    {
        return $this->hasMany(MemberCharge::class);
    }

    /**
     * @return HasMany<FneCertification, $this>
     */
    public function certifications(): HasMany
    {
        return $this->hasMany(FneCertification::class)->orderBy('attempt');
    }

    /**
     * @return BelongsTo<GeneratedDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class, 'document_id');
    }

    /**
     * @return BelongsTo<File, $this>
     */
    public function certifiedFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'certified_file_id');
    }
}
