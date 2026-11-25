<?php

use App\Domain\Referentials\Enums\DataStatus;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Directions régionales des impôts (F-REF-02, 05-BACKEND-SCHEMA §4) : gérées par le CNCGA, lisibles
 * par tous ; données provisoires jusqu'à leur validation (RG-DATA-01, 02).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regional_directorates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->string('geo_key', 50)->nullable();
            $table->boolean('active')->default(true);
            $table->string('data_status', 20)->default(DataStatus::Provisional->value);
            $table->timestampTz('validated_at')->nullable();
            $table->timestampsTz();
        });
        Constraints::enum('regional_directorates', 'data_status', DataStatus::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('regional_directorates');
    }
};
