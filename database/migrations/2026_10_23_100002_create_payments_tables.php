<?php

use App\Domain\Payments\Enums\ChargeKind;
use App\Domain\Payments\Enums\ChargeStatus;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentSource;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Droit d'adhésion, honoraires et paiements (05-BACKEND-SCHEMA §6, F-PAY-01 à 04) : sommes dues par
 * l'adhérent à son CGA, paiements saisis par le CGA ou déclarés par l'adhérent (preuve obligatoire),
 * ventilation de chaque paiement sur les sommes dues. Les factures FNE (étape 3.13) et les inscriptions
 * aux formations (Lot 7) se relient plus tard aux sommes dues.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_charges', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->foreignUlid('member_id')->constrained('members');
            $table->string('kind', 20);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->date('due_date');
            $table->bigInteger('amount');
            $table->bigInteger('amount_paid')->default(0);
            $table->string('status', 20)->default(ChargeStatus::Due->value);
            $table->date('overdue_since')->nullable();
            $table->char('training_registration_id', 26)->nullable();
            $table->char('invoice_id', 26)->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'status', 'due_date']);
            $table->index('member_id');
        });
        Constraints::enum('member_charges', 'kind', ChargeKind::class);
        Constraints::enum('member_charges', 'status', ChargeStatus::class);
        Constraints::check('member_charges', 'member_charges_amounts', 'amount >= 0 AND amount_paid >= 0 AND amount_paid <= amount');
        Constraints::check('member_charges', 'member_charges_fees_period', "kind <> 'fees' OR (period_start IS NOT NULL AND period_end IS NOT NULL AND period_start <= period_end)");
        // Idempotence de fees:generate : une seule échéance d'honoraires par période.
        DB::statement("CREATE UNIQUE INDEX member_charges_fees_period_unique ON member_charges (member_id, kind, period_start) WHERE kind = 'fees'");
        // Un seul droit d'adhésion en cours par dossier.
        DB::statement("CREATE UNIQUE INDEX member_charges_one_entry_fee ON member_charges (member_id) WHERE kind = 'entry_fee' AND status <> 'cancelled'");

        Schema::create('member_payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->foreignUlid('member_id')->constrained('members');
            $table->bigInteger('amount');
            $table->date('paid_on');
            $table->string('method', 20);
            $table->string('reference', 100)->nullable();
            $table->foreignUlid('proof_file_id')->nullable()->constrained('files');
            $table->string('source', 20);
            $table->string('status', 20);
            $table->text('rejection_reason')->nullable();
            $table->foreignUlid('confirmed_by')->nullable()->constrained('users');
            $table->timestampTz('confirmed_at')->nullable();
            $table->string('receipt_number', 40)->nullable();
            $table->foreignUlid('receipt_document_id')->nullable()->constrained('generated_documents');
            $table->foreignUlid('recorded_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->unique(['organization_id', 'receipt_number']);
            $table->index(['organization_id', 'status', 'paid_on']);
            $table->index('member_id');
        });
        Constraints::enum('member_payments', 'method', PaymentMethod::class);
        Constraints::enum('member_payments', 'source', PaymentSource::class);
        Constraints::enum('member_payments', 'status', PaymentStatus::class);
        Constraints::check('member_payments', 'member_payments_amount_positive', 'amount > 0');
        Constraints::check('member_payments', 'member_payments_declared_proof', "source <> 'member_declaration' OR proof_file_id IS NOT NULL");
        Constraints::check('member_payments', 'member_payments_rejection_reason', "status <> 'rejected' OR rejection_reason IS NOT NULL");

        Schema::create('member_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('payment_id')->constrained('member_payments');
            $table->foreignUlid('charge_id')->constrained('member_charges');
            $table->bigInteger('amount');

            $table->unique(['payment_id', 'charge_id']);
            $table->index('charge_id');
        });
        Constraints::check('member_payment_allocations', 'member_payment_allocations_amount_positive', 'amount > 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('member_payment_allocations');
        Schema::dropIfExists('member_payments');
        Schema::dropIfExists('member_charges');
    }
};
