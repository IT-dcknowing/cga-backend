<?php

use App\Domain\Members\Enums\EntityType;
use App\Domain\Members\Enums\FeesPeriodicity;
use App\Domain\Members\Enums\LegalForm;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\PiecesStatus;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Enums\TaxRegime;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Dossiers des adhérents (05-BACKEND-SCHEMA §5, F-ADH-02 à 10), dans l'un des deux portefeuilles du
 * centre (RG-PTF-01, 02). Un seul dossier ouvert par NCC dans un centre ; recherche plein texte par
 * trigrammes ; contrat CGA obligatoire hors brouillon dans le portefeuille CGA (RG-PTF-08) ;
 * apporteur seulement dans le portefeuille CGA (RG-PTF-06). La pièce d'identité du dirigeant est
 * chiffrée par le modèle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->string('portfolio', 10)->default(Portfolio::Cga->value);
            $table->string('status', 20)->default(MemberStatus::Draft->value);
            $table->string('membership_number', 40)->nullable();
            $table->integer('membership_seq')->nullable();
            $table->string('client_number', 40)->nullable();
            $table->string('legacy_number', 60)->nullable();
            $table->foreignUlid('referrer_id')->nullable()->constrained('business_referrers');
            $table->date('cga_contract_signed_on')->nullable();
            $table->foreignUlid('cga_contract_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('cga_contract_ended_on')->nullable();
            $table->foreignUlid('cga_contract_file_id')->nullable()->constrained('files');
            $table->boolean('attestation_blocked')->default(false);
            $table->date('adhesion_date')->nullable();
            $table->timestampTz('validated_at')->nullable();
            $table->foreignUlid('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('entity_type', 20);
            $table->string('company_name', 200);
            $table->string('sigle', 50)->nullable();
            $table->string('legal_form', 30);
            $table->bigInteger('share_capital')->nullable();
            $table->string('owner_last_name', 150)->nullable();
            $table->string('owner_first_names', 150)->nullable();
            $table->string('owner_nationality', 80)->nullable();
            $table->string('owner_id_document_type', 30)->nullable();
            $table->text('owner_id_document_number')->nullable();
            $table->string('ncc', 12);
            $table->string('rccm', 50)->nullable();
            $table->string('idu', 50)->nullable();
            $table->string('regime', 5);
            $table->date('regime_since');
            $table->foreignUlid('tax_office_id')->nullable()->constrained('tax_offices');
            $table->foreignUlid('activity_code_id')->nullable()->constrained('activity_codes');
            $table->string('activity_code_text', 20)->nullable();
            $table->foreignUlid('sector_id')->nullable()->constrained('sectors');
            $table->string('main_activity', 255);
            $table->text('address')->nullable();
            $table->foreignUlid('commune_id')->nullable()->constrained('communes');
            $table->string('land_section', 30)->nullable();
            $table->string('land_parcel', 30)->nullable();
            $table->date('registration_date')->nullable();
            $table->string('phone', 20);
            $table->string('whatsapp', 20)->nullable();
            $table->rawColumn('email', 'citext')->nullable();
            $table->string('signatory_name', 150)->nullable();
            $table->string('signatory_title', 100)->nullable();
            $table->integer('employees_count')->nullable();
            $table->bigInteger('entry_fee_amount')->default(0);
            $table->bigInteger('fees_amount')->default(0);
            $table->string('fees_periodicity', 15)->default(FeesPeriodicity::Monthly->value);
            $table->string('pieces_status', 20)->default(PiecesStatus::Pending->value);
            $table->date('rsi_since')->nullable();
            $table->text('search_text')->storedAs("lower(company_name || ' ' || coalesce(sigle, '') || ' ' || ncc || ' ' || coalesce(membership_number, '') || ' ' || coalesce(client_number, '') || ' ' || coalesce(legacy_number, '') || ' ' || phone)");
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['organization_id', 'membership_number']);
            $table->unique(['organization_id', 'client_number']);
            $table->index(['organization_id', 'portfolio', 'status']);
            $table->index(['organization_id', 'referrer_id']);
            $table->index(['organization_id', 'legacy_number']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'regime']);
            $table->index(['organization_id', 'tax_office_id']);
            $table->index(['organization_id', 'adhesion_date']);
        });

        foreach (['portfolio' => Portfolio::class, 'status' => MemberStatus::class, 'entity_type' => EntityType::class, 'legal_form' => LegalForm::class, 'regime' => TaxRegime::class, 'fees_periodicity' => FeesPeriodicity::class, 'pieces_status' => PiecesStatus::class] as $column => $enum) {
            Constraints::enum('members', $column, $enum);
        }
        Constraints::check('members', 'members_ncc_format', "ncc ~ '^[0-9]{7}[A-Z]$'");
        Constraints::check('members', 'members_employees_count_positive', 'employees_count IS NULL OR employees_count >= 0');
        Constraints::check('members', 'members_amounts_positive', 'entry_fee_amount >= 0 AND fees_amount >= 0');
        Constraints::check('members', 'members_referrer_cga_only', "referrer_id IS NULL OR portfolio = 'cga'");
        Constraints::check('members', 'members_cga_requires_contract', "portfolio <> 'cga' OR status = 'draft' OR cga_contract_signed_on IS NOT NULL");

        // Un seul dossier ouvert par NCC dans un centre, tous portefeuilles confondus (RG-ADH-05).
        DB::statement("CREATE UNIQUE INDEX members_open_ncc_unique ON members (organization_id, ncc) WHERE status IN ('draft', 'active', 'radiation_pending') AND deleted_at IS NULL");
        // Exclusivité entre CGA : NCC inscrits au portefeuille CGA (RG-ADH-05, T-22).
        DB::statement("CREATE INDEX members_cga_ncc_index ON members (ncc) WHERE portfolio = 'cga' AND status IN ('active', 'radiation_pending')");
        DB::statement('CREATE INDEX members_search_text_trgm ON members USING gin (search_text gin_trgm_ops)');

        Schema::table('memberships', function (Blueprint $table) {
            $table->foreign('member_id')->references('id')->on('members');
        });
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropForeign(['member_id']);
        });
        Schema::dropIfExists('members');
    }
};
