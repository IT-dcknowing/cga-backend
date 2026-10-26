<?php

use App\Domain\Users\Enums\UserStatus;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Personnes physiques : un compte par personne, identifié par son téléphone (RG-ACC-01,
 * 05-BACKEND-SCHEMA §2). Sessions Laravel (SESSION_DRIVER=database). Extensions du schéma (§0).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
        DB::statement('CREATE EXTENSION IF NOT EXISTS citext');

        Schema::create('users', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('phone', 20)->unique();
            $table->timestampTz('phone_verified_at')->nullable();
            $table->rawColumn('email', 'citext')->nullable();
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->string('status', 20)->default(UserStatus::Invited->value);
            $table->timestampTz('last_login_at')->nullable();
            $table->ulid('last_space_membership_id')->nullable();
            $table->timestampTz('disabled_at')->nullable();
            $table->timestampTz('inactivity_notice_sent_at')->nullable();
            $table->timestampsTz();

            $table->index('status');
        });
        DB::statement('CREATE UNIQUE INDEX users_email_unique ON users (email) WHERE email IS NOT NULL');
        Constraints::enum('users', 'status', UserStatus::class);

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->ulid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
