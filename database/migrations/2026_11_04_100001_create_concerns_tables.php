<?php

use App\Domain\Concerns\Enums\ConcernCategory;
use App\Domain\Concerns\Enums\ConcernLevel;
use App\Domain\Concerns\Enums\ConcernSide;
use App\Domain\Concerns\Enums\ConcernStatus;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Préoccupations (05-BACKEND-SCHEMA §12, PRD M10) : demandes d'un adhérent à son CGA ou d'un CGA au
 * CNCGA, leur fil de messages (notes internes invisibles du demandeur, messages système des changements
 * de statut, copie de l'échange d'origine pour un transfert au CNCGA, F-PRE-03) et leurs pièces jointes.
 * Toutes portent le CGA concerné (pour une demande au CNCGA, le CGA émetteur). « awaiting_reply_since » :
 * depuis quand le demandeur attend une réponse (alerte à 15 jours, RG-PRE-02, décision 0012).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('concerns', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->string('level', 20);
            $table->foreignUlid('member_id')->nullable()->constrained('members');
            $table->foreignUlid('author_user_id')->constrained('users');
            $table->string('category', 30);
            $table->string('subject', 200);
            $table->string('status', 20)->default(ConcernStatus::Open->value);
            $table->text('rejection_reason')->nullable();
            $table->ulid('transferred_to_concern_id')->nullable()->unique();
            $table->ulid('transferred_from_concern_id')->nullable()->unique();
            $table->smallInteger('target_response_days');
            $table->timestampTz('first_response_at')->nullable();
            $table->timestampTz('last_activity_at');
            $table->timestampTz('awaiting_reply_since')->nullable();
            $table->timestampTz('stale_alert_sent_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->smallInteger('reopen_count')->default(0);
            $table->timestampsTz();

            $table->index(['organization_id', 'level', 'status']);
            $table->index('member_id');
            $table->index(['level', 'status', 'awaiting_reply_since']);
        });
        // Demandes liées par un transfert (F-PRE-03) : clés posées une fois la table et sa clé primaire créées.
        Schema::table('concerns', function (Blueprint $table) {
            $table->foreign('transferred_to_concern_id')->references('id')->on('concerns');
            $table->foreign('transferred_from_concern_id')->references('id')->on('concerns');
        });
        Constraints::enum('concerns', 'level', ConcernLevel::class);
        Constraints::enum('concerns', 'category', ConcernCategory::class);
        Constraints::enum('concerns', 'status', ConcernStatus::class);
        Constraints::check('concerns', 'concerns_member_level', "(level = 'member_to_cga') = (member_id IS NOT NULL)");
        Constraints::check('concerns', 'concerns_rejection', "status <> 'rejected' OR rejection_reason IS NOT NULL");
        Constraints::check('concerns', 'concerns_transfer_levels', "(transferred_to_concern_id IS NULL OR level = 'member_to_cga') AND (transferred_from_concern_id IS NULL OR level = 'cga_to_cncga')");
        Constraints::check('concerns', 'concerns_counters', 'target_response_days > 0 AND reopen_count >= 0');

        Schema::create('concern_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->foreignUlid('concern_id')->constrained('concerns')->cascadeOnDelete();
            $table->foreignUlid('author_user_id')->constrained('users');
            $table->string('author_side', 20);
            $table->text('body');
            $table->boolean('is_internal')->default(false);
            $table->boolean('is_system')->default(false);
            $table->ulid('copied_from_message_id')->nullable();
            $table->string('copied_side', 20)->nullable();
            $table->timestampTz('created_at');

            $table->index(['concern_id', 'created_at']);
        });
        Schema::table('concern_messages', function (Blueprint $table) {
            $table->foreign('copied_from_message_id')->references('id')->on('concern_messages');
        });
        Constraints::enum('concern_messages', 'author_side', ConcernSide::class);
        Constraints::in('concern_messages', 'copied_side', ConcernSide::values());
        Constraints::check('concern_messages', 'concern_messages_internal', "NOT is_internal OR author_side = 'handler'");
        Constraints::check('concern_messages', 'concern_messages_copy', '(copied_from_message_id IS NULL) = (copied_side IS NULL)');

        Schema::create('concern_message_files', function (Blueprint $table) {
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->foreignUlid('message_id')->constrained('concern_messages')->cascadeOnDelete();
            $table->foreignUlid('file_id')->unique()->constrained('files');

            $table->primary(['message_id', 'file_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('concern_message_files');
        Schema::dropIfExists('concern_messages');
        Schema::dropIfExists('concerns');
    }
};
