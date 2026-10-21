<?php

use App\Domain\Members\Enums\FeeKind;
use App\Domain\Members\Enums\FeesPeriodicity;
use App\Domain\Members\Enums\MovementDirection;
use App\Domain\Members\Enums\PieceStatus;
use App\Domain\Members\Enums\PieceType;
use App\Domain\Members\Enums\RadiationInitiator;
use App\Domain\Members\Enums\RadiationOutcome;
use App\Domain\Members\Enums\RadiationReason;
use App\Domain\Members\Enums\RadiationStatus;
use App\Domain\Members\Enums\RevenueKind;
use App\Domain\Members\Enums\RevenueSource;
use App\Domain\Members\Enums\TaxRegime;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Ce que le dossier d'un adhérent accumule (05-BACKEND-SCHEMA §5, §3, §16) : historique des régimes
 * (F-ADH-05), chiffres d'affaires par année, pièces justificatives (F-PJ-01), radiations en deux temps
 * (RG-ADH-17), bascules entre portefeuilles (RG-PTF-05), réimports d'identité depuis un autre CGA
 * (RG-ADH-15), barème du CGA (F-PAY-01), justificatifs des modifications d'identité (RG-ADH-06b).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_regime_history', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained('organizations');
            $table->foreignUlid('member_id')->constrained('members');
            $table->string('regime', 5);
            $table->date('effective_date');
            $table->text('reason');
            $table->foreignUlid('justification_file_id')->nullable()->constrained('files');
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->nullable();

            $table->index(['member_id', 'effective_date']);
        });
        Constraints::enum('member_regime_history', 'regime', TaxRegime::class);

        Schema::create('member_revenues', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained('organizations');
            $table->foreignUlid('member_id')->constrained('members');
            $table->smallInteger('year');
            $table->bigInteger('amount');
            $table->string('kind', 20);
            $table->string('source', 20);
            $table->timestampTz('synced_at')->nullable();
            $table->timestampsTz();

            $table->unique(['member_id', 'year', 'kind']);
        });
        Constraints::enum('member_revenues', 'kind', RevenueKind::class);
        Constraints::enum('member_revenues', 'source', RevenueSource::class);
        Constraints::check('member_revenues', 'member_revenues_amount_positive', 'amount >= 0');

        Schema::create('member_pieces', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained('organizations');
            $table->foreignUlid('member_id')->constrained('members');
            $table->string('piece_type', 30);
            $table->string('status', 20)->default(PieceStatus::Expected->value);
            $table->foreignUlid('file_id')->nullable()->constrained('files');
            $table->date('received_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->timestampTz('last_reminder_at')->nullable();
            $table->timestampsTz();

            $table->unique(['member_id', 'piece_type']);
        });
        Constraints::enum('member_pieces', 'piece_type', PieceType::class);
        Constraints::enum('member_pieces', 'status', PieceStatus::class);

        Schema::create('radiations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->foreignUlid('member_id')->constrained('members');
            $table->string('initiator', 20);
            $table->string('reason', 30);
            $table->string('reason_other', 200)->nullable();
            $table->string('status', 20)->default(RadiationStatus::Requested->value);
            $table->date('effective_date');
            $table->text('observation');
            $table->foreignUlid('proof_file_id')->nullable()->constrained('files');
            $table->string('sp_request_reference', 100)->nullable();
            $table->foreignUlid('requested_by')->constrained('users');
            $table->timestampTz('requested_at');
            $table->foreignUlid('validated_by')->nullable()->constrained('users');
            $table->timestampTz('validated_at')->nullable();
            $table->string('validation_reference', 100)->nullable();
            $table->string('outcome', 20)->nullable();
            $table->char('notification_document_id', 26)->nullable();
            $table->smallInteger('report_year')->nullable();
            $table->smallInteger('report_quarter')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignUlid('cancelled_by')->nullable()->constrained('users');
            $table->text('cancel_reason')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'report_year', 'report_quarter']);
            $table->index(['organization_id', 'status']);
        });
        Constraints::enum('radiations', 'initiator', RadiationInitiator::class);
        Constraints::enum('radiations', 'reason', RadiationReason::class);
        Constraints::enum('radiations', 'status', RadiationStatus::class);
        Constraints::enum('radiations', 'outcome', RadiationOutcome::class);
        Constraints::check('radiations', 'radiations_report_quarter_range', 'report_quarter IS NULL OR report_quarter BETWEEN 1 AND 4');
        // Une seule radiation en vigueur (demandée ou validée) par dossier.
        DB::statement('CREATE UNIQUE INDEX radiations_member_current_unique ON radiations (member_id) WHERE cancelled_at IS NULL');

        Schema::create('portfolio_movements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->foreignUlid('member_id')->index()->constrained('members');
            $table->string('direction', 20);
            $table->date('effective_date');
            $table->text('reason');
            $table->date('contract_signed_on')->nullable();
            $table->foreignUlid('contract_file_id')->nullable()->constrained('files');
            $table->foreignUlid('radiation_id')->nullable()->constrained('radiations');
            $table->foreignUlid('previous_referrer_id')->nullable()->constrained('business_referrers');
            $table->foreignUlid('created_by')->constrained('users');
            $table->timestampTz('created_at')->nullable();

            $table->index(['organization_id', 'effective_date']);
        });
        Constraints::enum('portfolio_movements', 'direction', MovementDirection::class);
        Constraints::check('portfolio_movements', 'portfolio_movements_contract_to_cga', "direction <> 'cabinet_to_cga' OR contract_signed_on IS NOT NULL");
        Constraints::check('portfolio_movements', 'portfolio_movements_radiation_to_cabinet', "direction <> 'cga_to_cabinet' OR radiation_id IS NOT NULL");

        // Hors cloisonnement standard : le réimport relie deux CGA, par une Action dédiée journalisée.
        Schema::create('member_transfers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('from_member_id')->constrained('members');
            $table->foreignUlid('to_organization_id')->index()->constrained('organizations');
            $table->foreignUlid('to_member_id')->nullable()->constrained('members');
            $table->foreignUlid('consent_file_id')->constrained('files');
            $table->foreignUlid('requested_by')->constrained('users');
            $table->timestampTz('created_at')->nullable();
        });

        Schema::create('fee_schedules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->string('kind', 20);
            $table->string('regime', 5)->nullable();
            $table->bigInteger('amount');
            $table->string('periodicity', 15)->nullable();
            $table->date('valid_from');
            $table->timestampsTz();
        });
        Constraints::enum('fee_schedules', 'kind', FeeKind::class);
        Constraints::in('fee_schedules', 'regime', [TaxRegime::Rme->value, TaxRegime::Tee->value]);
        Constraints::enum('fee_schedules', 'periodicity', FeesPeriodicity::class);
        Constraints::check('fee_schedules', 'fee_schedules_amount_positive', 'amount >= 0');
        DB::statement("CREATE UNIQUE INDEX fee_schedules_unique ON fee_schedules (organization_id, kind, COALESCE(regime, ''), valid_from)");

        // Le journal des modifications est partitionné par mois : la pièce désigne l'entrée par son
        // identifiant, sans clé étrangère vers la table partitionnée.
        Schema::create('audit_attachments', function (Blueprint $table) {
            $table->bigInteger('audit_log_id');
            $table->foreignUlid('file_id')->constrained('files');
            $table->primary(['audit_log_id', 'file_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_attachments');
        Schema::dropIfExists('fee_schedules');
        Schema::dropIfExists('member_transfers');
        Schema::dropIfExists('portfolio_movements');
        Schema::dropIfExists('radiations');
        Schema::dropIfExists('member_pieces');
        Schema::dropIfExists('member_revenues');
        Schema::dropIfExists('member_regime_history');
    }
};
