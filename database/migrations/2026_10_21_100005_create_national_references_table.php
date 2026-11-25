<?php

use App\Domain\Referentials\Enums\DataStatus;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Références nationales annuelles (F-REF-04) : recettes fiscales intérieures et PIB nominal de l'année,
 * avec leur source, pour la contribution du réseau au budget de l'État et au PIB.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('national_references', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->smallInteger('year')->unique();
            $table->bigInteger('domestic_tax_revenue')->nullable();
            $table->bigInteger('nominal_gdp')->nullable();
            $table->string('tax_revenue_source', 200)->nullable();
            $table->string('gdp_source', 200)->nullable();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('data_status', 20)->default(DataStatus::Provisional->value);
            $table->timestampTz('validated_at')->nullable();
            $table->timestampsTz();
        });
        Constraints::enum('national_references', 'data_status', DataStatus::class);
        Constraints::check('national_references', 'national_references_amounts_check', 'domestic_tax_revenue >= 0 AND nominal_gdp >= 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('national_references');
    }
};
