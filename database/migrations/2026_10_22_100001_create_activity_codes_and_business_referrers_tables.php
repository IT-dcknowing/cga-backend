<?php

use App\Domain\Referentials\Enums\DataStatus;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Nomenclature DGI des codes d'activité (05-BACKEND-SCHEMA §4, chargée quand elle sera fournie,
 * données provisoires) et apporteurs d'affaires du centre (sous-portefeuille « Accessoires »,
 * RG-PTF-06, §5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_codes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code', 20)->unique();
            $table->string('label', 200);
            $table->foreignUlid('sector_id')->nullable()->index()->constrained('sectors');
            $table->boolean('active')->default(true);
            $table->string('data_status', 20)->default(DataStatus::Provisional->value);
            $table->timestampTz('validated_at')->nullable();
            $table->timestampsTz();
        });
        Constraints::enum('activity_codes', 'data_status', DataStatus::class);

        Schema::create('business_referrers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained('organizations');
            $table->string('label', 40);
            $table->string('name', 150);
            $table->string('phone', 20)->nullable();
            $table->rawColumn('email', 'citext')->nullable();
            $table->text('terms')->nullable();
            $table->boolean('active')->default(true);
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['organization_id', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_referrers');
        Schema::dropIfExists('activity_codes');
    }
};
