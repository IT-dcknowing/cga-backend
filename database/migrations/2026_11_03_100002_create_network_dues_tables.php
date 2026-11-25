<?php

use App\Domain\Dues\Enums\DuesPaymentSource;
use App\Domain\Dues\Enums\DuesStatus;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Cotisation des CGA au CNCGA (05-BACKEND-SCHEMA §10, PRD M14) : une cotisation par CGA et par année,
 * au prorata semestriel pour un centre arrivé en cours d'année (RG-COT-02) ; ses paiements, déclarés
 * par le CGA ou saisis par le CNCGA, confirmés avec un reçu numéroté. La cotisation est distincte de
 * l'abonnement à la plateforme (RG-COT-03).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_dues', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->smallInteger('year');
            $table->bigInteger('base_amount');
            $table->decimal('prorata_ratio', 4, 3)->default(1);
            $table->bigInteger('amount_due');
            $table->bigInteger('amount_paid')->default(0);
            $table->date('deadline');
            $table->string('status', 20)->default(DuesStatus::Due->value);
            $table->timestampTz('last_reminder_at')->nullable();
            $table->smallInteger('reminder_count')->default(0);
            // Facture de cotisation certifiée FNE émise par le CNCGA (étape 6.9).
            $table->foreignUlid('invoice_id')->nullable()->constrained('invoices');
            $table->timestampsTz();

            $table->unique(['organization_id', 'year']);
            $table->index(['year', 'status']);
        });
        Constraints::enum('network_dues', 'status', DuesStatus::class);
        Constraints::check('network_dues', 'network_dues_amounts', 'base_amount >= 0 AND amount_due >= 0 AND amount_paid >= 0 AND prorata_ratio > 0 AND prorata_ratio <= 1');

        Schema::create('network_due_payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->foreignUlid('network_due_id')->constrained('network_dues');
            $table->bigInteger('amount');
            $table->date('paid_on');
            $table->string('method', 20);
            $table->string('reference', 100)->nullable();
            $table->foreignUlid('proof_file_id')->nullable()->constrained('files');
            $table->string('source', 20);
            $table->string('status', 30);
            $table->text('rejection_reason')->nullable();
            $table->foreignUlid('confirmed_by')->nullable()->constrained('users');
            $table->timestampTz('confirmed_at')->nullable();
            $table->string('receipt_number', 40)->nullable()->unique();
            $table->foreignUlid('receipt_document_id')->nullable()->constrained('generated_documents');
            $table->foreignUlid('created_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->index(['network_due_id', 'status']);
        });
        Constraints::enum('network_due_payments', 'method', PaymentMethod::class);
        Constraints::enum('network_due_payments', 'source', DuesPaymentSource::class);
        Constraints::enum('network_due_payments', 'status', PaymentStatus::class);
        Constraints::check('network_due_payments', 'network_due_payments_amount', 'amount > 0');
        Constraints::check('network_due_payments', 'network_due_payments_declaration_proof', "source <> 'cga_declaration' OR proof_file_id IS NOT NULL");
        Constraints::check('network_due_payments', 'network_due_payments_rejection', "status <> 'rejected' OR rejection_reason IS NOT NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('network_due_payments');
        Schema::dropIfExists('network_dues');
    }
};
