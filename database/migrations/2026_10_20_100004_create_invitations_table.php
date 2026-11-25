<?php

use App\Domain\Auth\Enums\InvitationChannel;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Invitations (F-AUTH-02) : seule l'empreinte SHA-256 du jeton est stockée (02-TRD §6.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('membership_id')->index()->constrained('memberships')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('channel', 20);
            $table->timestampTz('sent_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
        });
        Constraints::enum('invitations', 'channel', InvitationChannel::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
