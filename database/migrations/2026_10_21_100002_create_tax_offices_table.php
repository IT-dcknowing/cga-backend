<?php

use App\Domain\Referentials\Enums\DataStatus;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Centres des impôts (CDI) d'une direction régionale (F-REF-02, 05-BACKEND-SCHEMA §4). Une DR qui a
 * des CDI ne se supprime pas (RG-DATA-05).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_offices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('regional_directorate_id')->index()->constrained('regional_directorates')->restrictOnDelete();
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->boolean('active')->default(true);
            $table->string('data_status', 20)->default(DataStatus::Provisional->value);
            $table->timestampTz('validated_at')->nullable();
            $table->timestampsTz();
        });
        Constraints::enum('tax_offices', 'data_status', DataStatus::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_offices');
    }
};
