<?php

use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Trainings\Enums\Attendance;
use App\Domain\Trainings\Enums\AudienceType;
use App\Domain\Trainings\Enums\RegistrationStatus;
use App\Domain\Trainings\Enums\TrainingFormat;
use App\Domain\Trainings\Enums\TrainingPaymentStatus;
use App\Domain\Trainings\Enums\TrainingStatus;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Formations et séminaires (05-BACKEND-SCHEMA §13, PRD M11) :
 * - trainings : organisées par le CNCGA (réseau) ou un CGA (ses agents et adhérents), cloisonnées à
 *   l'organisateur ; motif d'annulation et date de clôture de la session (décision 0013) ;
 * - training_audiences : publics ciblés (RG-FOR-01, 02) ;
 * - training_registrations : une par personne et par formation hors annulation, cloisonnées au CGA de la
 *   personne inscrite (attendee_organization_id du schéma) et rattachées à son espace (membership_id) ;
 *   liste d'attente (RG-FOR-03), paiement et reçu (RG-FOR-06), émargement, attestation, évaluation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trainings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('format', 20);
            $table->string('location', 255)->nullable();
            $table->string('online_url', 500)->nullable();
            $table->string('trainer', 200)->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->integer('capacity')->nullable();
            $table->bigInteger('price')->default(0);
            $table->boolean('requires_approval')->default(false);
            $table->string('status', 20)->default(TrainingStatus::Draft->value);
            $table->timestampTz('published_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->foreignUlid('created_by')->constrained('users');
            $table->timestampsTz();

            $table->index(['organization_id', 'status', 'starts_at']);
            $table->index(['status', 'starts_at']);
        });
        Constraints::enum('trainings', 'format', TrainingFormat::class);
        Constraints::enum('trainings', 'status', TrainingStatus::class);
        Constraints::check('trainings', 'trainings_dates', 'ends_at >= starts_at');
        Constraints::check('trainings', 'trainings_amounts', 'price >= 0 AND (capacity IS NULL OR capacity > 0)');
        Constraints::check('trainings', 'trainings_cancellation', "status <> 'cancelled' OR (cancelled_at IS NOT NULL AND cancellation_reason IS NOT NULL)");

        Schema::create('training_audiences', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->foreignUlid('training_id')->constrained('trainings')->cascadeOnDelete();
            $table->string('audience', 30);
            $table->string('regime', 5)->nullable();
            $table->smallInteger('new_member_months')->nullable();

            $table->unique(['training_id', 'audience', 'regime']);
        });
        Constraints::enum('training_audiences', 'audience', AudienceType::class);
        Constraints::in('training_audiences', 'regime', [TaxRegime::Rme->value, TaxRegime::Tee->value]);
        Constraints::check('training_audiences', 'training_audiences_regime', "(audience = 'members_by_regime') = (regime IS NOT NULL)");
        Constraints::check('training_audiences', 'training_audiences_new_members', "(audience = 'new_members') = (new_member_months IS NOT NULL AND new_member_months > 0)");

        Schema::create('training_registrations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->foreignUlid('training_id')->constrained('trainings');
            $table->foreignUlid('membership_id')->constrained('memberships');
            $table->foreignUlid('user_id')->constrained('users');
            $table->foreignUlid('member_id')->nullable()->constrained('members');
            $table->foreignUlid('registered_by')->constrained('users');
            $table->string('status', 20);
            $table->integer('waitlist_position')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('payment_status', 20)->default(TrainingPaymentStatus::NotApplicable->value);
            $table->date('paid_on')->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->string('receipt_number', 40)->nullable()->unique();
            $table->foreignUlid('receipt_document_id')->nullable()->constrained('generated_documents');
            $table->string('attendance', 10)->nullable();
            $table->foreignUlid('certificate_document_id')->nullable()->constrained('generated_documents');
            $table->smallInteger('evaluation_score')->nullable();
            $table->text('evaluation_comment')->nullable();
            $table->timestampTz('evaluated_at')->nullable();
            $table->timestampTz('promoted_at')->nullable();
            $table->timestampTz('reminded_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();

            $table->index(['training_id', 'status', 'waitlist_position']);
            $table->index(['organization_id', 'training_id']);
            $table->index('user_id');
        });
        Constraints::enum('training_registrations', 'status', RegistrationStatus::class);
        Constraints::enum('training_registrations', 'payment_status', TrainingPaymentStatus::class);
        Constraints::in('training_registrations', 'payment_method', PaymentMethod::values());
        Constraints::enum('training_registrations', 'attendance', Attendance::class);
        Constraints::check('training_registrations', 'training_registrations_waitlist', "(status = 'waitlisted') = (waitlist_position IS NOT NULL)");
        Constraints::check('training_registrations', 'training_registrations_paid', "payment_status <> 'paid' OR (paid_on IS NOT NULL AND payment_method IS NOT NULL AND receipt_number IS NOT NULL)");
        Constraints::check('training_registrations', 'training_registrations_score', 'evaluation_score IS NULL OR evaluation_score BETWEEN 1 AND 5');
        Constraints::check('training_registrations', 'training_registrations_cancelled', "status <> 'cancelled' OR cancelled_at IS NOT NULL");
        // Une inscription par personne et par formation, une annulée n'empêchant pas de se réinscrire.
        DB::statement("CREATE UNIQUE INDEX training_registrations_person_unique ON training_registrations (training_id, user_id) WHERE status <> 'cancelled'");
    }

    public function down(): void
    {
        Schema::dropIfExists('training_registrations');
        Schema::dropIfExists('training_audiences');
        Schema::dropIfExists('trainings');
    }
};
