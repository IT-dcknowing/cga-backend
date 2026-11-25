<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Jours fériés, gérés par le CNCGA (05-BACKEND-SCHEMA §4) : BusinessCalendar en tient compte pour le
 * report au jour ouvré suivant (RG-OBL-06). Leur API et leur jeu provisoire arrivent à l'étape 1.1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('label', 100);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
