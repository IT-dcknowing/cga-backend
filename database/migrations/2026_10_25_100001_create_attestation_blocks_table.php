<?php

use App\Domain\Documents\Enums\AttestationBlockTrigger;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Blocage manuel de l'attestation d'un adhérent (RG-DOC-10, 05-BACKEND-SCHEMA §5) : décidé par le
 * gérant, sur alerte (honoraires impayés, RSI non radié) ou de sa propre initiative, motif obligatoire,
 * puis débloqué avec un motif. Un seul blocage en cours par dossier ; members.attestation_blocked le
 * reflète. Jamais de blocage automatique (CR-04).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attestation_blocks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->foreignUlid('member_id')->constrained('members');
            $table->string('trigger', 20);
            $table->text('reason');
            $table->foreignUlid('blocked_by')->constrained('users');
            $table->timestampTz('blocked_at');
            $table->foreignUlid('unblocked_by')->nullable()->constrained('users');
            $table->timestampTz('unblocked_at')->nullable();
            $table->text('unblock_reason')->nullable();

            $table->index(['organization_id', 'blocked_at']);
            $table->index(['member_id', 'blocked_at']);
        });
        Constraints::enum('attestation_blocks', 'trigger', AttestationBlockTrigger::class);
        Constraints::check('attestation_blocks', 'attestation_blocks_unblock_complete', '(unblocked_at IS NULL AND unblocked_by IS NULL AND unblock_reason IS NULL) OR (unblocked_at IS NOT NULL AND unblocked_by IS NOT NULL AND unblock_reason IS NOT NULL)');
        DB::statement('CREATE UNIQUE INDEX attestation_blocks_one_active ON attestation_blocks (member_id) WHERE unblocked_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('attestation_blocks');
    }
};
