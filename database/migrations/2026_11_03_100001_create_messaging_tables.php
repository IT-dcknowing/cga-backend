<?php

use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Moteur de notifications (05-BACKEND-SCHEMA §14, 02-TRD §10, PRD M13) : notifications dans la
 * plateforme (table standard Laravel, données jsonb rattachées à l'espace), journal des envois externes,
 * comptes d'envoi paramétrables (RG-NOT-05) et quotas SMS mensuels par centre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('notifiable_type');
            $table->char('notifiable_id', 26);
            $table->jsonb('data');
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });

        Schema::create('messaging_providers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('channel', 20);
            $table->string('driver', 30);
            $table->string('label', 100);
            $table->jsonb('settings')->default('{}');
            $table->text('secrets');
            $table->boolean('is_active')->default(false);
            $table->timestampTz('last_test_at')->nullable();
            $table->text('last_test_result')->nullable();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        Constraints::in('messaging_providers', 'channel', ['sms', 'whatsapp', 'email']);
        Constraints::in('messaging_providers', 'driver', ['log', 'orange_ci', 'http_generic', 'whatsapp_cloud', 'smtp']);
        // Un seul compte actif par canal (RG-NOT-05).
        DB::statement('CREATE UNIQUE INDEX messaging_providers_active_channel ON messaging_providers (channel) WHERE is_active');

        Schema::create('message_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('organization_id')->nullable()->constrained('organizations');
            $table->char('campaign_id', 26)->nullable();
            $table->string('notification_type', 80)->nullable();
            $table->string('channel', 20);
            $table->string('recipient', 150);
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20);
            $table->string('provider_message_id', 100)->nullable();
            $table->integer('cost_units')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['organization_id', 'created_at']);
            $table->index('campaign_id');
        });
        Constraints::in('message_logs', 'channel', ['email', 'sms', 'whatsapp']);
        Constraints::in('message_logs', 'status', ['queued', 'sent', 'delivered', 'failed']);

        Schema::create('sms_quotas', function (Blueprint $table) {
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->date('month');
            $table->integer('included');
            $table->integer('extra')->default(0);
            $table->integer('used')->default(0);
            $table->timestampsTz();

            $table->primary(['organization_id', 'month']);
        });
        Constraints::check('sms_quotas', 'sms_quotas_positive', 'included >= 0 AND extra >= 0 AND used >= 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_quotas');
        Schema::dropIfExists('message_logs');
        Schema::dropIfExists('messaging_providers');
        Schema::dropIfExists('notifications');
    }
};
