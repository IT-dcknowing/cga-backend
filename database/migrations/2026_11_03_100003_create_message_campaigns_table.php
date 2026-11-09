<?php

use App\Domain\Notifications\Enums\CampaignStatus;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Envois groupés d'un centre (05-BACKEND-SCHEMA §14, F-COM-03) : message, canaux, filtre des
 * destinataires, nombre, validation par le gérant au-delà du seuil (RG-COM-01), envoi ; chaque envoi
 * externe est rattaché à sa campagne dans message_logs (historique et délivrance).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_campaigns', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->string('title', 200);
            $table->text('body');
            $table->string('template_key', 60)->nullable();
            $table->jsonb('channels');
            $table->jsonb('recipient_filter');
            $table->integer('recipients_count');
            $table->string('status', 30);
            $table->foreignUlid('requested_by')->constrained('users');
            $table->foreignUlid('approved_by')->nullable()->constrained('users');
            $table->timestampTz('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'created_at']);
        });
        Constraints::enum('message_campaigns', 'status', CampaignStatus::class);
        Constraints::check('message_campaigns', 'message_campaigns_count', 'recipients_count >= 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('message_campaigns');
    }
};
