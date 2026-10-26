<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Valeurs des paramètres déclarés dans config/cga.php (02-TRD §7.5) : sans organisation pour le
 * réseau et la plateforme, avec l'organisation pour la valeur propre d'un CGA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('organization_id')->nullable()->index()->constrained('organizations')->cascadeOnDelete();
            $table->string('key', 100);
            $table->jsonb('value');
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        DB::statement("CREATE UNIQUE INDEX settings_organization_key_unique ON settings (COALESCE(organization_id, ''), key)");
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
