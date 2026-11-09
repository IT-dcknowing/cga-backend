<?php

use App\Domain\Platform\Enums\BackupStatus;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Passages de la sauvegarde de nuit (02-TRD §12.3, étape 9.1, décision 0018) : début et fin, résultat, fichiers
 * chiffrés produits et leur taille, niveau de l'archive des fichiers, copie hors du serveur, message d'échec.
 * Donnée de la plateforme, sans organisation ; lue par le point de santé et l'espace /plateforme.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('status', 20);
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->string('database_file', 255)->nullable();
            $table->bigInteger('database_bytes')->nullable();
            $table->string('files_file', 255)->nullable();
            $table->string('files_level', 20)->nullable();
            $table->bigInteger('files_bytes')->nullable();
            $table->boolean('remote_copied')->default(false);
            $table->text('error')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'finished_at']);
        });
        Constraints::enum('backup_runs', 'status', BackupStatus::class);
        Constraints::check('backup_runs', 'backup_runs_files_level', "files_level IS NULL OR files_level IN ('full', 'incremental')");
        Constraints::check('backup_runs', 'backup_runs_done', "status <> 'done' OR (finished_at IS NOT NULL AND database_file IS NOT NULL AND files_file IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
    }
};
