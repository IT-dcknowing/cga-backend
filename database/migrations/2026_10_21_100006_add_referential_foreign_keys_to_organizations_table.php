<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Rattachement d'un CGA à sa DR, son CDI et sa commune (05-BACKEND-SCHEMA §3) : un référentiel utilisé
 * ne se supprime pas (RG-DATA-05).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->foreign('regional_directorate_id')->references('id')->on('regional_directorates')->restrictOnDelete();
            $table->foreign('tax_office_id')->references('id')->on('tax_offices')->restrictOnDelete();
            $table->foreign('commune_id')->references('id')->on('communes')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropForeign(['regional_directorate_id']);
            $table->dropForeign(['tax_office_id']);
            $table->dropForeign(['commune_id']);
        });
    }
};
