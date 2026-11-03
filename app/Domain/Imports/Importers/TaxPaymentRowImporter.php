<?php

namespace App\Domain\Imports\Importers;

use App\Domain\Imports\Contracts\RowImporter;
use App\Domain\Imports\Data\RowOutcome;
use App\Domain\Imports\Support\CellValue;
use App\Domain\Imports\Support\HistoryValues;
use App\Domain\Imports\Support\MemberLookup;
use App\Domain\Members\Models\Member;
use App\Domain\Obligations\Enums\InstanceKind;
use App\Domain\Obligations\Enums\InstanceStatus;
use App\Domain\Obligations\Enums\ObligationCategory;
use App\Domain\Obligations\Enums\ObligationPaymentSource;
use App\Domain\Obligations\Enums\TaxNature;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Domain\Obligations\Models\ObligationPayment;
use App\Domain\Obligations\Support\InstanceAmounts;
use App\Domain\Obligations\Support\ObligationPeriod;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Import de l'historique des paiements d'impôts (F-IMP-03, 06-IMPLEMENTATION-PLAN étape 5.6) : par ligne,
 * le dossier, la nature d'impôt, la période (`2025`, `2025-S1`, `2025-T3`, `2025-03`), le montant payé, la
 * date du paiement, et au besoin le montant dû et une référence. Le paiement, confirmé d'office (source
 * « import »), est rattaché à l'échéance de même nature qui couvre la période, ou à une échéance
 * d'historique créée pour l'occasion (échéance réputée à la fin de la période, montant dû déclaré ou, à
 * défaut, montant payé). Il alimente les états du rapport (Suivi TEE, Suivi RME, surclassements).
 * Réimporter le même paiement est refusé (référence, ou empreinte de la ligne) ; une échéance réglée par
 * Compta Flow n'est pas modifiable (RG-DATA-06) ; un paiement ne dépasse jamais le reste dû.
 */
class TaxPaymentRowImporter implements RowImporter
{
    public function __construct(
        private readonly InstanceAmounts $amounts,
        private readonly TenantContext $context,
    ) {}

    public function columns(): array
    {
        return [
            'member' => ['label' => 'N° d\'adhérent ou NCC *', 'help' => __('imports.history.help.member'), 'aliases' => ['N° adhérent', 'Numéro', 'NCC', 'Dossier']],
            'nature' => ['label' => 'Nature d\'impôt *', 'help' => __('imports.tax_payments.help.nature'), 'aliases' => ['Nature', 'Impôt']],
            'period' => ['label' => 'Période *', 'help' => __('imports.tax_payments.help.period'), 'aliases' => ['Période']],
            'amount' => ['label' => 'Montant payé *', 'help' => __('imports.tax_payments.help.amount'), 'aliases' => ['Montant payé', 'Montant', 'Payé']],
            'paid_on' => ['label' => 'Date du paiement *', 'help' => __('imports.tax_payments.help.paid_on'), 'aliases' => ['Date du paiement', 'Date', 'Payé le']],
            'amount_due' => ['label' => 'Montant dû', 'help' => __('imports.tax_payments.help.amount_due'), 'aliases' => ['Dû', 'Montant à payer']],
            'reference' => ['label' => 'Référence', 'help' => __('imports.tax_payments.help.reference'), 'aliases' => ['Quittance', 'N° de quittance']],
        ];
    }

    public function requiredColumns(): array
    {
        return ['member', 'nature', 'period', 'amount', 'paid_on'];
    }

    public function labelColumn(): string
    {
        return 'member';
    }

    public function fileName(): string
    {
        return 'import-historique-paiements';
    }

    public function import(array $values): RowOutcome
    {
        $designation = CellValue::text($values['member'] ?? null);
        $natureText = CellValue::text($values['nature'] ?? null);
        $nature = HistoryValues::taxNature($natureText);
        $periodText = CellValue::text($values['period'] ?? null);
        $period = HistoryValues::period($periodText);
        $amount = HistoryValues::integer($values['amount'] ?? null);
        $dueText = CellValue::text($values['amount_due'] ?? null);
        $due = $dueText === null ? null : HistoryValues::integer($values['amount_due']);
        $paidOn = CellValue::date($values['paid_on'] ?? null);
        $reference = CellValue::text($values['reference'] ?? null);
        $member = $designation === null ? null : MemberLookup::find($designation);

        $reasons = array_values(array_filter([
            $designation === null ? __('imports.history.required', ['field' => 'N° d\'adhérent ou NCC']) : null,
            $designation !== null && $member === null ? __('imports.history.unknown_member', ['value' => $designation]) : null,
            $member === false ? __('imports.history.ambiguous_member', ['value' => $designation]) : null,
            $nature === null ? __('imports.tax_payments.invalid_nature', ['value' => (string) $natureText]) : null,
            $period === null ? __('imports.tax_payments.invalid_period', ['value' => (string) $periodText]) : null,
            $amount === null || $amount <= 0 ? __('imports.history.invalid_amount', ['field' => 'Montant payé']) : null,
            $dueText !== null && ($due === null || $due <= 0) ? __('imports.history.invalid_amount', ['field' => 'Montant dû']) : null,
            ! $paidOn instanceof CarbonImmutable ? __('imports.tax_payments.invalid_date') : null,
            $paidOn instanceof CarbonImmutable && $paidOn->isFuture() ? __('imports.tax_payments.future_date') : null,
            $reference !== null && mb_strlen($reference) > 100 ? __('imports.tax_payments.reference_too_long') : null,
        ]));
        if ($reasons !== [] || ! $member instanceof Member || $nature === null || $period === null || $amount === null || ! $paidOn instanceof CarbonImmutable) {
            return RowOutcome::rejected($designation, $reasons === [] ? [(string) __('imports.cgas.invalid_row')] : $reasons);
        }

        $externalRef = $reference ?? 'ligne-'.substr(hash('sha256', implode('|', [$member->id, $nature->value, $period->label, $paidOn->toDateString(), $amount])), 0, 40);
        if (ObligationPayment::query()->where('source', ObligationPaymentSource::Import)->where('external_ref', $externalRef)->exists()) {
            return RowOutcome::rejected($designation, [__('imports.tax_payments.already_imported')]);
        }

        $instance = $this->instance($member, $nature, $period);
        if ($instance !== null && ObligationPayment::query()->where('instance_id', $instance->id)->where('source', ObligationPaymentSource::ComptaFlow)->exists()) {
            return RowOutcome::rejected($designation, [__('imports.history.compta_flow')]);
        }
        $instance ??= $this->historyInstance($member, $nature, $period, $due ?? $amount);
        if ($instance->amount_due === null) {
            $instance->amount_due = $due ?? $instance->amount_paid + $amount;
        }
        if ($amount > $instance->amount_due - $instance->amount_paid) {
            return RowOutcome::rejected($designation, [__('imports.tax_payments.exceeds_balance', ['balance' => $instance->amount_due - $instance->amount_paid])]);
        }
        $instance->save();

        $actor = $this->context->membership()->user_id;
        $payment = new ObligationPayment;
        $payment->organization_id = $instance->organization_id;
        $payment->instance_id = $instance->id;
        $payment->member_id = $member->id;
        $payment->tax_nature = $nature;
        $payment->amount = $amount;
        $payment->paid_on = $paidOn;
        $payment->source = ObligationPaymentSource::Import;
        $payment->external_ref = $externalRef;
        $payment->status = PaymentStatus::Confirmed;
        $payment->confirmed_by = $actor;
        $payment->confirmed_at = CarbonImmutable::now();
        $payment->recorded_by = $actor;
        $payment->save();
        $this->amounts->refresh($instance);

        return RowOutcome::imported($payment->id, $member->company_name);
    }

    /**
     * Échéance de paiement de cette nature pour la période : même période d'abord (une TEE du 2e trimestre
     * échoit en juillet), sinon celle dont l'échéance tombe dans la période.
     */
    private function instance(Member $member, TaxNature $nature, ObligationPeriod $period): ?ObligationInstance
    {
        $candidates = ObligationInstance::query()
            ->where('member_id', $member->id)
            ->where('kind', InstanceKind::Payment)
            ->where('tax_nature', $nature)
            ->where('status', '<>', InstanceStatus::Cancelled)
            ->where(static fn ($query) => $query
                ->where('period_label', $period->label)
                ->orWhereBetween('due_date', [$period->start->toDateString(), $period->end->toDateString()]))
            ->lockForUpdate()
            ->orderBy('due_date')
            ->get();

        return $candidates->first(static fn (ObligationInstance $instance): bool => $instance->period_label === $period->label) ?? $candidates->first();
    }

    private function historyInstance(Member $member, TaxNature $nature, ObligationPeriod $period, int $due): ObligationInstance
    {
        $instance = new ObligationInstance;
        $instance->organization_id = $member->organization_id;
        $instance->member_id = $member->id;
        $instance->label = (string) __('imports.tax_payments.instance_label', ['nature' => __('enums.tax_nature.'.$nature->value), 'period' => $period->label]);
        $instance->category = match ($nature) {
            TaxNature::Cnps => ObligationCategory::Cnps,
            TaxNature::Cmu => ObligationCategory::Cmu,
            default => ObligationCategory::Tax,
        };
        $instance->kind = InstanceKind::Payment;
        $instance->period_label = $period->label;
        $instance->period_start = $period->start;
        $instance->period_end = $period->end;
        $instance->due_date = $period->end;
        $instance->tax_nature = $nature;
        $instance->amount_due = $due;
        $instance->amount_paid = 0;
        $instance->status = InstanceAmounts::status($due, 0);

        return $instance;
    }
}
