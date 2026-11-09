<?php

use App\Domain\Obligations\Enums\CenterObligationKind;
use App\Domain\Obligations\Enums\CenterObligationStatus;
use App\Domain\Obligations\Enums\InstanceKind;
use App\Domain\Obligations\Enums\InstanceStatus;
use App\Domain\Obligations\Enums\MemberObligationSource;
use App\Domain\Obligations\Enums\MemberObligationStatus;
use App\Domain\Obligations\Enums\ObligationCategory;
use App\Domain\Obligations\Enums\ObligationKind;
use App\Domain\Obligations\Enums\ObligationPaymentSource;
use App\Domain\Obligations\Enums\ObligationPeriodicity;
use App\Domain\Obligations\Enums\TaxNature;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Referentials\Enums\DataStatus;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Obligations fiscales et sociales (05-BACKEND-SCHEMA §4 et §7, PRD M07, étape 4.1) : catalogue
 * national versionné et obligations locales d'un CGA (RG-OBL-01), obligations attribuées à un
 * adhérent (RG-OBL-02), échéances générées sans doublon (RG-OBL-04), paiements d'une échéance (recettes
 * fiscales du rapport), obligations propres du CGA (F-OBL-07). Le catalogue est une donnée de
 * référence provisoire, remplaçable par fichier (RG-DATA-01, 04).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('obligation_types', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Nul : catalogue national ; renseigné : obligation locale d'un CGA (RG-OBL-01).
            $table->foreignUlid('organization_id')->nullable()->constrained('organizations');
            $table->string('catalog_version', 20);
            $table->string('code', 20);
            $table->string('label', 200);
            $table->string('category', 20);
            $table->string('authority', 100);
            $table->string('platform', 50)->nullable();
            $table->string('kind', 20);
            $table->string('periodicity', 20);
            $table->jsonb('due_rule');
            $table->jsonb('applicability');
            $table->string('tax_nature', 20)->nullable();
            $table->boolean('has_abatement')->default(false);
            $table->boolean('creates_payment_on_done')->default(true);
            $table->boolean('active')->default(true);
            $table->text('legal_basis')->nullable();
            $table->string('data_status', 20)->default(DataStatus::Provisional->value);
            $table->timestampTz('validated_at')->nullable();
            $table->timestampsTz();

            $table->index(['catalog_version', 'active']);
        });
        Constraints::enum('obligation_types', 'category', ObligationCategory::class);
        Constraints::enum('obligation_types', 'kind', ObligationKind::class);
        Constraints::enum('obligation_types', 'periodicity', ObligationPeriodicity::class);
        Constraints::enum('obligation_types', 'tax_nature', TaxNature::class);
        Constraints::enum('obligation_types', 'data_status', DataStatus::class);
        DB::statement("CREATE UNIQUE INDEX obligation_types_code_unique ON obligation_types (COALESCE(organization_id, ''), code, catalog_version)");

        Schema::create('member_obligations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->foreignUlid('member_id')->constrained('members');
            $table->foreignUlid('obligation_type_id')->constrained('obligation_types');
            $table->string('source', 20);
            $table->string('status', 20);
            $table->foreignUlid('confirmed_by')->nullable()->constrained('users');
            $table->timestampTz('confirmed_at')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'status']);
            $table->index('member_id');
        });
        Constraints::enum('member_obligations', 'source', MemberObligationSource::class);
        Constraints::enum('member_obligations', 'status', MemberObligationStatus::class);
        Constraints::check('member_obligations', 'member_obligations_period', 'end_date IS NULL OR end_date >= start_date');
        DB::statement("CREATE UNIQUE INDEX member_obligations_one_open ON member_obligations (member_id, obligation_type_id) WHERE status <> 'removed'");

        Schema::create('obligation_instances', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->foreignUlid('member_id')->constrained('members');
            $table->foreignUlid('member_obligation_id')->nullable()->constrained('member_obligations');
            $table->foreignUlid('obligation_type_id')->nullable()->constrained('obligation_types');
            $table->string('label', 200);
            $table->string('category', 20);
            $table->string('kind', 20);
            $table->string('authority_name', 150)->nullable();
            $table->string('period_label', 30);
            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_date');
            $table->string('platform', 50)->nullable();
            $table->string('tax_nature', 20)->nullable();
            $table->bigInteger('amount_gross')->nullable();
            $table->bigInteger('amount_due')->nullable();
            $table->bigInteger('amount_paid')->default(0);
            $table->smallInteger('months_covered')->nullable();
            $table->string('status', 30);
            $table->string('member_declared_status', 30)->nullable();
            $table->timestampTz('member_declared_at')->nullable();
            $table->ulid('parent_instance_id')->nullable();
            $table->date('done_at')->nullable();
            $table->timestampTz('reminder_j7_sent_at')->nullable();
            $table->timestampTz('reminder_j1_sent_at')->nullable();
            $table->timestampTz('overdue_notified_at')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'due_date', 'status']);
            $table->index(['member_id', 'category', 'due_date']);
        });
        // Clé vers la table elle-même, posée une fois sa clé primaire créée (RG-OBL-05).
        Schema::table('obligation_instances', function (Blueprint $table) {
            $table->foreign('parent_instance_id')->references('id')->on('obligation_instances');
        });
        Constraints::enum('obligation_instances', 'category', ObligationCategory::class);
        Constraints::enum('obligation_instances', 'kind', InstanceKind::class);
        Constraints::enum('obligation_instances', 'tax_nature', TaxNature::class);
        Constraints::enum('obligation_instances', 'status', InstanceStatus::class);
        Constraints::enum('obligation_instances', 'member_declared_status', InstanceStatus::class);
        Constraints::check('obligation_instances', 'obligation_instances_period', 'period_start <= period_end');
        Constraints::check('obligation_instances', 'obligation_instances_amounts', '(amount_gross IS NULL OR amount_gross >= 0) AND (amount_due IS NULL OR amount_due >= 0) AND amount_paid >= 0');
        // Idempotence de obligations:generate (RG-OBL-04) : une échéance par obligation, nature et période.
        DB::statement('CREATE UNIQUE INDEX obligation_instances_generated_unique ON obligation_instances (member_obligation_id, kind, period_label) WHERE member_obligation_id IS NOT NULL');

        Schema::create('obligation_payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->foreignUlid('instance_id')->constrained('obligation_instances');
            $table->foreignUlid('member_id')->constrained('members');
            $table->string('tax_nature', 20);
            $table->bigInteger('amount');
            $table->date('paid_on');
            $table->string('source', 20);
            $table->string('external_ref', 100)->nullable();
            $table->string('status', 20);
            $table->foreignUlid('proof_file_id')->nullable()->constrained('files');
            $table->foreignUlid('confirmed_by')->nullable()->constrained('users');
            $table->timestampTz('confirmed_at')->nullable();
            $table->foreignUlid('recorded_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->unique(['organization_id', 'source', 'external_ref']);
            $table->index(['organization_id', 'status', 'paid_on']);
            $table->index(['tax_nature', 'paid_on']);
            $table->index('instance_id');
        });
        Constraints::enum('obligation_payments', 'tax_nature', TaxNature::class);
        Constraints::enum('obligation_payments', 'source', ObligationPaymentSource::class);
        Constraints::enum('obligation_payments', 'status', PaymentStatus::class);
        Constraints::check('obligation_payments', 'obligation_payments_amount_positive', 'amount > 0');
        // Une ligne Compta Flow est confirmée d'office (RG-DATA-06).
        Constraints::check('obligation_payments', 'obligation_payments_compta_flow_confirmed', "source <> 'compta_flow' OR (status = 'confirmed' AND external_ref IS NOT NULL)");

        Schema::create('center_obligations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->string('label', 200);
            $table->string('kind', 30);
            $table->date('due_date');
            $table->bigInteger('amount')->nullable();
            $table->string('status', 20);
            $table->text('notes')->nullable();
            $table->timestampTz('reminder_sent_at')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'due_date', 'status']);
        });
        Constraints::enum('center_obligations', 'kind', CenterObligationKind::class);
        Constraints::enum('center_obligations', 'status', CenterObligationStatus::class);
        Constraints::check('center_obligations', 'center_obligations_amount', 'amount IS NULL OR amount >= 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('center_obligations');
        Schema::dropIfExists('obligation_payments');
        Schema::dropIfExists('obligation_instances');
        Schema::dropIfExists('member_obligations');
        Schema::dropIfExists('obligation_types');
    }
};
