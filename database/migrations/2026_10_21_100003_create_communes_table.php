<?php

use App\Domain\Referentials\Enums\DataStatus;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Communes (F-REF-02, 05-BACKEND-SCHEMA §4), rattachées ou non à une direction régionale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 120)->unique();
            $table->foreignUlid('regional_directorate_id')->nullable()->index()->constrained('regional_directorates')->restrictOnDelete();
            $table->string('data_status', 20)->default(DataStatus::Provisional->value);
            $table->timestampTz('validated_at')->nullable();
            $table->timestampsTz();
        });
        Constraints::enum('communes', 'data_status', DataStatus::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('communes');
    }
};
