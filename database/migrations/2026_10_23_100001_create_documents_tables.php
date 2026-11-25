<?php

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\DocumentType;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Documents (05-BACKEND-SCHEMA §8, 02-TRD §8) : modèles versionnés (un seul actif par type), documents
 * produits ou déposés (numéro, version, jeton de vérification, empreinte, fichiers DOCX / PDF / XLSX),
 * vérifications publiques par QR code (RG-DOC-09).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('type', 40);
            $table->string('version', 20);
            $table->foreignUlid('file_id')->constrained('files');
            $table->jsonb('variables');
            $table->boolean('is_active')->default(false);
            $table->timestampTz('approved_by_cncga_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->unique(['type', 'version']);
        });
        // Un document déposé (F-DOC-05) n'a pas de modèle.
        Constraints::in('document_templates', 'type', collect(DocumentType::cases())
            ->reject(static fn (DocumentType $type): bool => $type === DocumentType::Uploaded)
            ->map(static fn (DocumentType $type): string => $type->value)
            ->values()
            ->all());
        DB::statement('CREATE UNIQUE INDEX document_templates_one_active ON document_templates (type) WHERE is_active');

        Schema::create('generated_documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->foreignUlid('member_id')->nullable()->constrained('members');
            $table->string('type', 40);
            $table->string('title', 200);
            $table->string('number', 60)->nullable();
            $table->smallInteger('version')->default(1);
            $table->ulid('replaces_document_id')->nullable();
            $table->foreignUlid('template_id')->nullable()->constrained('document_templates');
            $table->foreignUlid('docx_file_id')->nullable()->constrained('files');
            $table->foreignUlid('pdf_file_id')->nullable()->constrained('files');
            $table->foreignUlid('xlsx_file_id')->nullable()->constrained('files');
            $table->string('verification_token', 64)->nullable()->unique();
            $table->char('sha256', 64)->nullable();
            $table->string('status', 20)->default(DocumentStatus::Generating->value);
            $table->timestampTz('issued_at')->nullable();
            $table->date('valid_until')->nullable();
            $table->smallInteger('fiscal_year')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignUlid('cancelled_by')->nullable()->constrained('users');
            $table->text('cancel_reason')->nullable();
            $table->boolean('visible_to_member')->default(true);
            $table->string('source_type', 40)->nullable();
            $table->char('source_id', 26)->nullable();
            $table->foreignUlid('generated_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->unique(['organization_id', 'type', 'number']);
            $table->index(['member_id', 'type']);
            $table->index(['organization_id', 'type', 'issued_at']);
        });
        Schema::table('generated_documents', function (Blueprint $table) {
            // Régénération (RG-ADH-07) : la version suivante désigne celle qu'elle remplace.
            $table->foreign('replaces_document_id')->references('id')->on('generated_documents');
        });
        Constraints::enum('generated_documents', 'type', DocumentType::class);
        Constraints::enum('generated_documents', 'status', DocumentStatus::class);
        Constraints::check('generated_documents', 'generated_documents_version_positive', 'version >= 1');
        Constraints::check('generated_documents', 'generated_documents_cancel_reason', "status <> 'cancelled' OR (cancelled_at IS NOT NULL AND cancel_reason IS NOT NULL)");
        // Une attestation annuelle d'origine par adhérent et par année ; les duplicatas ont une version > 1.
        DB::statement("CREATE UNIQUE INDEX generated_documents_one_certificate_per_year ON generated_documents (member_id, fiscal_year) WHERE type = 'membership_certificate' AND status = 'valid' AND version = 1");

        Schema::create('document_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('document_id')->constrained('generated_documents');
            $table->string('ip_prefix', 50)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestampTz('verified_at');

            $table->index(['document_id', 'verified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_verifications');
        Schema::dropIfExists('generated_documents');
        Schema::dropIfExists('document_templates');
    }
};
