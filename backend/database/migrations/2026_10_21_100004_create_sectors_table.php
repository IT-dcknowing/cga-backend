<?php

use App\Domain\Referentials\Enums\DataStatus;
use App\Domain\Referentials\Enums\SectorStatus;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Secteurs d'activité (F-REF-01, RG-REF-01, 05-BACKEND-SCHEMA §4) : validés, visibles de tous ;
 * proposés par un CGA, visibles de lui et du CNCGA ; rejetés, ou fusionnés dans un autre secteur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sectors', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code', 10)->unique();
            $table->string('name', 120);
            $table->string('status', 20)->default(SectorStatus::Validated->value);
            $table->foreignUlid('proposed_by_organization_id')->nullable()->index()->constrained('organizations')->nullOnDelete();
            $table->ulid('merged_into_id')->nullable();
            $table->foreignUlid('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('validated_at')->nullable();
            $table->string('data_status', 20)->default(DataStatus::Provisional->value);
            $table->timestampsTz();

            $table->index('status');
        });
        // Clé vers la table elle-même : posée une fois la clé primaire créée.
        Schema::table('sectors', function (Blueprint $table) {
            $table->foreign('merged_into_id')->references('id')->on('sectors')->restrictOnDelete();
        });
        Constraints::enum('sectors', 'status', SectorStatus::class);
        Constraints::enum('sectors', 'data_status', DataStatus::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('sectors');
    }
};
