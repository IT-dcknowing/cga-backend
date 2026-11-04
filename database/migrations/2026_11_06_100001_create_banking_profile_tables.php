<?php

use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lot 10.1 : activation et consentement bancaire individuel. Le consentement de l'adhérent est
 * distinct du commutateur banking_enabled de l'organisation. Les événements sont historisés.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banking_profiles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->foreignUlid('member_id')->constrained('members');
            $table->boolean('consent_granted')->default(false);
            $table->string('consent_version', 40)->nullable();
            $table->char('consent_text_sha256', 64)->nullable();
            $table->timestampTz('consented_at')->nullable();
            $table->foreignUlid('consented_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('withdrawn_at')->nullable();
            $table->timestampsTz();

            $table->unique('member_id');
            $table->index(['organization_id', 'consent_granted']);
        });

        Constraints::check(
            'banking_profiles',
            'banking_profiles_consent_evidence',
            "consent_granted = false OR (consent_version IS NOT NULL AND consent_text_sha256 IS NOT NULL AND consented_at IS NOT NULL AND consented_by IS NOT NULL AND withdrawn_at IS NULL)"
        );
        Constraints::check(
            'banking_profiles',
            'banking_profiles_consent_hash_format',
            "consent_text_sha256 IS NULL OR consent_text_sha256 ~ '^[0-9a-f]{64}$'"
        );

        Schema::create('banking_consent_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->foreignUlid('member_id')->constrained('members');
            $table->string('event', 20);
            $table->string('consent_version', 40);
            $table->char('consent_text_sha256', 64);
            $table->foreignUlid('actor_user_id')->constrained('users');
            $table->foreignUlid('membership_id')->nullable()->constrained('memberships')->nullOnDelete();
            $table->timestampTz('occurred_at');
            $table->timestampsTz();

            $table->index(['member_id', 'occurred_at']);
            $table->index(['organization_id', 'event', 'occurred_at']);
        });

        Constraints::in('banking_consent_events', 'event', ['granted', 'withdrawn']);
        Constraints::check(
            'banking_consent_events',
            'banking_consent_events_hash_format',
            "consent_text_sha256 ~ '^[0-9a-f]{64}$'"
        );
        DB::statement('CREATE INDEX banking_profiles_org_member_idx ON banking_profiles (organization_id, member_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('banking_consent_events');
        Schema::dropIfExists('banking_profiles');
    }
};
