<?php

use App\Domain\Organizations\Enums\AgreementStatus;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Enums\SubscriptionStatus;
use App\Domain\Referentials\Enums\DataStatus;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * CNCGA, CGA et organisation technique « plateforme » (05-BACKEND-SCHEMA §3). Les clés étrangères
 * vers les référentiels (DR, CDI, communes) arrivent avec eux (étape 1.1) ; celles vers les fichiers
 * avec la table files.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('type', 20);
            $table->string('name', 200);
            $table->string('sigle', 50)->nullable();
            $table->string('numbering_abbreviation', 6)->nullable()->unique();
            $table->timestampTz('abbreviation_locked_at')->nullable();
            $table->string('legal_form', 40)->nullable();
            $table->bigInteger('share_capital')->nullable();
            $table->string('ncc', 20)->nullable();
            $table->string('rccm', 50)->nullable();
            $table->string('agreement_number', 60)->nullable();
            $table->date('agreement_date')->nullable();
            $table->date('agreement_expires_at')->nullable();
            $table->string('agreement_status', 20)->default(AgreementStatus::Pending->value);
            $table->date('agreement_withdrawn_at')->nullable();
            $table->ulid('regional_directorate_id')->nullable()->index();
            $table->ulid('tax_office_id')->nullable()->index();
            $table->string('said', 100)->nullable();
            $table->text('address')->nullable();
            $table->string('city', 100);
            $table->ulid('commune_id')->nullable()->index();
            $table->string('phone_1', 20);
            $table->string('phone_2', 20)->nullable();
            $table->rawColumn('email', 'citext')->nullable();
            $table->string('manager_name', 150)->nullable();
            $table->string('expert_name', 150)->nullable();
            $table->string('signatory_name', 150)->nullable();
            $table->string('signatory_title', 100)->nullable();
            $table->ulid('logo_file_id')->nullable();
            $table->ulid('signature_file_id')->nullable();
            $table->ulid('stamp_file_id')->nullable();
            $table->text('history_text')->nullable();
            $table->bigInteger('previous_year_revenue')->nullable();
            $table->string('status', 20)->default(OrganizationStatus::Active->value);
            $table->timestampTz('suspended_at')->nullable();
            $table->text('suspension_reason')->nullable();
            $table->string('subscription_status', 20)->default(SubscriptionStatus::None->value);
            $table->date('subscription_started_at')->nullable();
            $table->date('trial_ends_at')->nullable();
            $table->date('subscription_ended_at')->nullable();
            $table->date('data_deletion_due_at')->nullable();
            $table->boolean('banking_enabled')->default(false);
            $table->timestampTz('profile_completed_at')->nullable();
            // Registre importé : données provisoires remplaçables (RG-DATA-01, 05-BACKEND-SCHEMA §4).
            $table->string('data_status', 20)->default(DataStatus::Provisional->value);
            $table->timestampTz('validated_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['type', 'status']);
            $table->index('subscription_status');
        });

        DB::statement("CREATE UNIQUE INDEX organizations_single_cncga ON organizations (type) WHERE type = 'cncga'");
        DB::statement("CREATE UNIQUE INDEX organizations_single_platform ON organizations (type) WHERE type = 'platform'");
        DB::statement('CREATE UNIQUE INDEX organizations_agreement_number_unique ON organizations (agreement_number) WHERE agreement_number IS NOT NULL');

        Constraints::enum('organizations', 'type', OrganizationType::class);
        Constraints::enum('organizations', 'status', OrganizationStatus::class);
        Constraints::enum('organizations', 'agreement_status', AgreementStatus::class);
        Constraints::enum('organizations', 'subscription_status', SubscriptionStatus::class);
        Constraints::enum('organizations', 'data_status', DataStatus::class);
        Constraints::check('organizations', 'organizations_numbering_abbreviation_format', "numbering_abbreviation ~ '^[A-Z]{2,6}$'");
        Constraints::check('organizations', 'organizations_share_capital_positive', 'share_capital >= 0');
        Constraints::check('organizations', 'organizations_previous_year_revenue_positive', 'previous_year_revenue >= 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
