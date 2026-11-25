<?php

use App\Domain\Auth\Enums\OtpPurpose;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Codes envoyés par SMS ou e-mail (mot de passe oublié, changement de téléphone) : empreinte seule,
 * 3 essais au plus (02-TRD §6.1, 05-BACKEND-SCHEMA §2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('user_id')->index()->constrained('users')->cascadeOnDelete();
            $table->string('purpose', 30);
            $table->string('target', 120);
            $table->char('code_hash', 64);
            $table->smallInteger('attempts')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();
        });
        Constraints::enum('otp_codes', 'purpose', OtpPurpose::class);
        Constraints::check('otp_codes', 'otp_codes_attempts_range', 'attempts BETWEEN 0 AND 3');
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};
