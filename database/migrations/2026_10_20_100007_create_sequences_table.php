<?php

use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Compteurs des numérotations (02-TRD §7.1) : une ligne par organisation, clé et année ; année nulle
 * pour une séquence continue (numéro d'adhésion). Incrémentés par SequenceService, verrou de ligne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('key', 40);
            $table->smallInteger('year')->nullable();
            $table->bigInteger('last_value')->default(0);
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX sequences_organization_key_year_unique ON sequences (organization_id, key, COALESCE(year, 0))');
        Constraints::check('sequences', 'sequences_last_value_positive', 'last_value >= 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('sequences');
    }
};
