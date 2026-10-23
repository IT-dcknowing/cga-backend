<?php

use App\Domain\Documents\Enums\UploadedDocumentCategory;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Documents (étape 3.5) : une régénération (RG-ADH-07) ou un duplicata d'attestation (RG-DOC-06) garde
 * le numéro du document d'origine avec une version de plus, d'où l'unicité du numéro par version ; un
 * document déposé par le CGA a une catégorie (F-DOC-05).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_documents', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'type', 'number']);
            $table->unique(['organization_id', 'type', 'number', 'version']);
            $table->string('category', 40)->nullable()->after('title');
        });
        Constraints::enum('generated_documents', 'category', UploadedDocumentCategory::class);
        Constraints::check('generated_documents', 'generated_documents_category_uploaded', "category IS NULL OR type = 'uploaded'");
    }

    public function down(): void
    {
        Schema::table('generated_documents', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'type', 'number', 'version']);
            $table->unique(['organization_id', 'type', 'number']);
            $table->dropColumn('category');
        });
    }
};
