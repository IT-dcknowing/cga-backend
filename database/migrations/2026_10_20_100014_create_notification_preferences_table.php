<?php

use App\Domain\Notifications\Enums\PreferenceChannel;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Refus des SMS et WhatsApp non essentiels, et date du consentement recueilli à l'activation
 * (RG-NOT-04, loi n° 2013-450 ; 05-BACKEND-SCHEMA §14). Une ligne par utilisateur et par canal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel', 20);
            $table->boolean('opted_out');
            $table->timestampTz('consented_at')->nullable();

            $table->primary(['user_id', 'channel']);
        });
        Constraints::enum('notification_preferences', 'channel', PreferenceChannel::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
