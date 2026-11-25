<?php

use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fichiers stockés sur le disque (jamais de binaire en base, 05-BACKEND-SCHEMA §16). La taille
 * maximale d'un dépôt (files.max_upload_mb) est contrôlée à la validation du dépôt, les documents
 * produits par la plateforme pouvant être plus lourds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()->index()->constrained('organizations');
            $table->string('disk', 20);
            $table->string('path', 500)->unique();
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->bigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->foreignUlid('uploaded_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->nullable();
        });
        Constraints::check('files', 'files_size_bytes_positive', 'size_bytes >= 0');

        Schema::table('organizations', function (Blueprint $table) {
            $table->foreign('logo_file_id')->references('id')->on('files')->nullOnDelete();
            $table->foreign('signature_file_id')->references('id')->on('files')->nullOnDelete();
            $table->foreign('stamp_file_id')->references('id')->on('files')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropForeign(['logo_file_id']);
            $table->dropForeign(['signature_file_id']);
            $table->dropForeign(['stamp_file_id']);
        });
        Schema::dropIfExists('files');
    }
};
