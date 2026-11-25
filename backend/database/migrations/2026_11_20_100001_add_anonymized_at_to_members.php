<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Date d'anonymisation d'un dossier radié ou clos (PRD §7) : marque le dossier comme traité par
 * `retention:purge`, au lieu de reconnaître un dossier anonymisé à sa dénomination.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->timestampTz('anonymized_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('anonymized_at');
        });
    }
};
