<?php

use App\Domain\Reports\Enums\CgaPersonKind;
use App\Domain\Reports\Enums\CgaResourceCategory;
use App\Domain\Reports\Enums\ReportStatus;
use App\Domain\Reports\Enums\TransmissionMode;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Rapport trimestriel (05-BACKEND-SCHEMA §9, PRD M09) : fiche permanente du CGA (dirigeants, personnel,
 * moyens matériels, F-RPT-01), un rapport par CGA, année et trimestre (F-RPT-02), ses versions générées
 * avec leur instantané (RG-RPT-05).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cga_people', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->string('kind', 20);
            $table->string('full_name', 200);
            $table->string('nationality', 80)->nullable();
            $table->string('profession', 150)->nullable();
            $table->string('diplomas', 255)->nullable();
            $table->string('function_in_cga', 150)->nullable();
            $table->date('start_date')->nullable();
            $table->string('practice_structure', 150)->nullable();
            $table->string('practice_function', 150)->nullable();
            $table->smallInteger('practice_years')->nullable();
            $table->boolean('cnps_declared')->nullable();
            $table->boolean('active')->default(true);
            $table->date('end_date')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'kind', 'active']);
        });
        Constraints::enum('cga_people', 'kind', CgaPersonKind::class);
        Constraints::check('cga_people', 'cga_people_practice_years', 'practice_years IS NULL OR practice_years >= 0');
        Constraints::check('cga_people', 'cga_people_end_date', 'end_date IS NULL OR start_date IS NULL OR end_date >= start_date');

        Schema::create('cga_resources', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->string('category', 30);
            $table->integer('quantity');
            $table->text('description')->nullable();
            $table->text('need')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'category']);
        });
        Constraints::enum('cga_resources', 'category', CgaResourceCategory::class);
        Constraints::check('cga_resources', 'cga_resources_quantity', 'quantity >= 0');

        Schema::create('quarterly_reports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->smallInteger('year');
            $table->smallInteger('quarter');
            $table->string('status', 20)->default(ReportStatus::Draft->value);
            $table->date('deadline');
            $table->date('legal_deadline');
            $table->boolean('is_late')->default(false);
            $table->boolean('is_legally_late')->default(false);
            $table->jsonb('reminders_sent')->default('[]');
            $table->jsonb('qualitative_analysis')->default('{}');
            $table->date('report_date')->nullable();
            $table->string('regional_directorate_label', 150)->nullable();
            $table->jsonb('content')->default('{}');
            $table->jsonb('people_taxes')->default('[]');
            $table->jsonb('completeness')->default('{}');
            $table->smallInteger('current_version')->default(0);
            $table->timestampTz('transmitted_at')->nullable();
            $table->string('transmission_mode', 20)->nullable();
            $table->string('transmission_reference', 100)->nullable();
            $table->foreignUlid('transmitted_by')->nullable()->constrained('users');
            $table->date('acknowledged_at')->nullable();
            $table->string('acknowledgement_reference', 100)->nullable();
            $table->foreignUlid('acknowledgement_file_id')->nullable()->constrained('files');
            $table->jsonb('summary')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'year', 'quarter']);
            $table->index(['year', 'quarter', 'status']);
        });
        Constraints::enum('quarterly_reports', 'status', ReportStatus::class);
        Constraints::enum('quarterly_reports', 'transmission_mode', TransmissionMode::class);
        Constraints::check('quarterly_reports', 'quarterly_reports_quarter', 'quarter BETWEEN 1 AND 4');
        Constraints::check('quarterly_reports', 'quarterly_reports_legal_deadline', 'legal_deadline >= deadline');
        Constraints::check('quarterly_reports', 'quarterly_reports_transmission', "status NOT IN ('transmitted', 'acknowledged') OR (transmitted_at IS NOT NULL AND transmission_mode IS NOT NULL)");

        Schema::create('quarterly_report_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->foreignUlid('report_id')->constrained('quarterly_reports');
            $table->smallInteger('version');
            $table->text('reason')->nullable();
            $table->jsonb('snapshot')->nullable();
            $table->foreignUlid('xlsx_document_id')->nullable()->constrained('generated_documents');
            $table->foreignUlid('docx_document_id')->nullable()->constrained('generated_documents');
            $table->foreignUlid('pdf_document_id')->nullable()->constrained('generated_documents');
            $table->foreignUlid('slip_document_id')->nullable()->constrained('generated_documents');
            $table->timestampTz('generated_at')->nullable();
            $table->foreignUlid('generated_by')->nullable()->constrained('users');
            $table->timestampTz('transmitted_at')->nullable();
            $table->timestampsTz();

            $table->unique(['report_id', 'version']);
        });
        Constraints::check('quarterly_report_versions', 'quarterly_report_versions_version', 'version >= 1');
        Constraints::check('quarterly_report_versions', 'quarterly_report_versions_reason', 'version = 1 OR reason IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('quarterly_report_versions');
        Schema::dropIfExists('quarterly_reports');
        Schema::dropIfExists('cga_resources');
        Schema::dropIfExists('cga_people');
    }
};
