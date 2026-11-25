<?php

use App\Domain\Exports\Enums\ExportStatus;
use App\Domain\Exports\Enums\ExportType;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Exports Excel en tâche de fond (F-EXP-01, RG-SEC-06, 02-TRD §1, décision 0017) : un export par demande, cloisonné à
 * l'espace qui l'a demandé, rattaché à l'accès de la personne qui exporte (son périmètre), avec la période et les
 * filtres retenus, son avancement, le nombre de lignes et le fichier produit (horodaté).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->foreignUlid('membership_id')->constrained('memberships');
            $table->foreignUlid('user_id')->constrained('users');
            $table->string('type', 30);
            $table->string('period_type', 10)->nullable();
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->jsonb('filters')->default('{}');
            $table->string('status', 20)->default(ExportStatus::Queued->value);
            $table->integer('rows_count')->default(0);
            $table->foreignUlid('file_id')->nullable()->constrained('files');
            $table->timestampTz('requested_at');
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'membership_id', 'requested_at']);
        });
        Constraints::enum('exports', 'type', ExportType::class);
        Constraints::enum('exports', 'status', ExportStatus::class);
        Constraints::check('exports', 'exports_period', '(period_from IS NULL) = (period_to IS NULL) AND (period_to IS NULL OR period_to >= period_from)');
        Constraints::check('exports', 'exports_file', "status <> 'done' OR file_id IS NOT NULL");
        Constraints::check('exports', 'exports_rows', 'rows_count >= 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('exports');
    }
};
