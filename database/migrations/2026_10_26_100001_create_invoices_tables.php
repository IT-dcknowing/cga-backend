<?php

use App\Domain\Payments\Enums\CertificationAttemptStatus;
use App\Domain\Payments\Enums\CertificationMode;
use App\Domain\Payments\Enums\InvoiceStatus;
use App\Domain\Payments\Enums\InvoiceType;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Factures certifiées FNE (06-IMPLEMENTATION-PLAN étape 3.13, F-PAY-05, F-PAY-06, 05-BACKEND-SCHEMA §6) :
 * factures et avoirs, échanges avec le service de certification, mode de certification de chaque
 * organisation. Les sommes dues portent désormais leur montant hors taxe, le taux et la TVA ; le
 * montant dû (amount) est toutes taxes comprises (barème hors taxe, TVA paramétrable, décision du
 * 23/10/2026). Les sommes déjà ouvertes sont reprises sans TVA, à l'identique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_charges', function (Blueprint $table) {
            $table->bigInteger('amount_excl_tax')->nullable()->after('due_date');
            $table->decimal('tax_rate', 5, 2)->default(0)->after('amount_excl_tax');
            $table->bigInteger('tax_amount')->default(0)->after('tax_rate');
        });
        DB::statement('UPDATE member_charges SET amount_excl_tax = amount');
        Schema::table('member_charges', function (Blueprint $table) {
            $table->bigInteger('amount_excl_tax')->nullable(false)->change();
        });
        Constraints::check('member_charges', 'member_charges_tax', 'amount_excl_tax >= 0 AND tax_amount >= 0 AND tax_rate >= 0 AND tax_rate <= 100 AND amount = amount_excl_tax + tax_amount');

        Schema::create('invoicing_providers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->unique()->constrained('organizations');
            $table->string('mode', 20)->default(CertificationMode::Manual->value);
            $table->string('base_url', 255)->nullable();
            $table->text('credentials')->nullable();
            $table->string('fne_number_pattern', 120)->nullable();
            $table->boolean('active')->default(true);
            $table->timestampTz('last_test_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        Constraints::enum('invoicing_providers', 'mode', CertificationMode::class);

        Schema::create('invoices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->string('type', 20)->default(InvoiceType::Invoice->value);
            $table->ulid('original_invoice_id')->nullable();
            $table->foreignUlid('member_id')->nullable()->constrained('members');
            $table->foreignUlid('billed_organization_id')->nullable()->constrained('organizations');
            $table->string('number', 40);
            $table->date('issue_date');
            $table->jsonb('lines');
            $table->bigInteger('amount_excl_tax');
            $table->bigInteger('tax_amount');
            $table->bigInteger('amount_incl_tax');
            $table->string('status', 30)->default(InvoiceStatus::Draft->value);
            $table->string('certification_mode', 20);
            $table->string('fne_number', 80)->nullable();
            $table->text('fne_qr_payload')->nullable();
            $table->text('fne_stamp')->nullable();
            $table->timestampTz('certified_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignUlid('certified_file_id')->nullable()->constrained('files');
            $table->foreignUlid('document_id')->nullable()->constrained('generated_documents');
            $table->text('cancel_reason')->nullable();
            $table->foreignUlid('created_by')->constrained('users');
            $table->timestampsTz();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status']);
            $table->index('member_id');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreign('original_invoice_id')->references('id')->on('invoices');
        });
        Constraints::enum('invoices', 'type', InvoiceType::class);
        Constraints::enum('invoices', 'status', InvoiceStatus::class);
        Constraints::enum('invoices', 'certification_mode', CertificationMode::class);
        Constraints::check('invoices', 'invoices_one_client', '(member_id IS NULL) <> (billed_organization_id IS NULL)');
        Constraints::check('invoices', 'invoices_credit_note_original', "type <> 'credit_note' OR original_invoice_id IS NOT NULL");
        Constraints::check('invoices', 'invoices_certified_number', "status <> 'certified' OR (fne_number IS NOT NULL AND certified_at IS NOT NULL)");
        Constraints::check('invoices', 'invoices_amounts', 'amount_excl_tax >= 0 AND tax_amount >= 0 AND amount_incl_tax = amount_excl_tax + tax_amount');
        // Un seul avoir en cours ou certifié par facture (avoir total, décision du 23/10/2026).
        DB::statement("CREATE UNIQUE INDEX invoices_one_credit_note ON invoices (original_invoice_id) WHERE type = 'credit_note' AND status <> 'cancelled'");

        Schema::create('fne_certifications', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->foreignUlid('invoice_id')->constrained('invoices');
            $table->smallInteger('attempt');
            $table->timestampTz('requested_at');
            $table->timestampTz('responded_at')->nullable();
            $table->string('status', 20);
            $table->string('provider_reference', 100)->nullable();
            $table->text('message')->nullable();
            $table->timestampTz('next_retry_at')->nullable();

            $table->unique(['invoice_id', 'attempt']);
            $table->index('next_retry_at');
        });
        Constraints::enum('fne_certifications', 'status', CertificationAttemptStatus::class);

        Schema::table('member_charges', function (Blueprint $table) {
            $table->foreign('invoice_id')->references('id')->on('invoices');
        });
    }

    public function down(): void
    {
        Schema::table('member_charges', function (Blueprint $table) {
            $table->dropForeign(['invoice_id']);
        });
        Schema::dropIfExists('fne_certifications');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('invoicing_providers');
        DB::statement('ALTER TABLE member_charges DROP CONSTRAINT IF EXISTS member_charges_tax');
        Schema::table('member_charges', function (Blueprint $table) {
            $table->dropColumn(['amount_excl_tax', 'tax_rate', 'tax_amount']);
        });
    }
};
