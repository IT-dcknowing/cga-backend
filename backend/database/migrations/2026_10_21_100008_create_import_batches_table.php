<?php

use App\Domain\Imports\Enums\ImportRowStatus;
use App\Domain\Imports\Enums\ImportStatus;
use App\Domain\Imports\Enums\ImportType;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Imports de fichiers Excel (M17, 05-BACKEND-SCHEMA §16) : un lot par fichier déposé, avec ses
 * compteurs et son rapport d'anomalies, et le sort de chaque ligne (importée, à compléter, rejetée).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()->index()->constrained('organizations');
            $table->string('type', 20);
            $table->foreignUlid('file_id')->constrained('files');
            $table->string('status', 20)->default(ImportStatus::Queued->value);
            $table->integer('total_rows')->default(0);
            $table->integer('imported_rows')->default(0);
            $table->integer('draft_rows')->default(0);
            $table->integer('rejected_rows')->default(0);
            $table->foreignUlid('report_file_id')->nullable()->constrained('files');
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
        });
        Constraints::enum('import_batches', 'type', ImportType::class);
        Constraints::enum('import_batches', 'status', ImportStatus::class);
        Constraints::check('import_batches', 'import_batches_counts_positive', 'total_rows >= 0 AND imported_rows >= 0 AND draft_rows >= 0 AND rejected_rows >= 0');

        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('batch_id')->constrained('import_batches');
            $table->integer('row_number');
            $table->string('status', 20);
            $table->jsonb('messages')->default('[]');
            $table->char('created_entity_id', 26)->nullable();
            $table->unique(['batch_id', 'row_number']);
        });
        Constraints::enum('import_rows', 'status', ImportRowStatus::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_batches');
    }
};
