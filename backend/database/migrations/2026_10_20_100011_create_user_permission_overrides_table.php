<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Habilitations par utilisateur (RG-ROLE-07, 05-BACKEND-SCHEMA §2) : un droit accordé en plus du
 * profil ou retiré, pour un rattachement. Lues par PermissionResolver ; leur API arrive à l'étape 1.11.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_permission_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('organization_id')->index()->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('membership_id')->constrained('memberships')->cascadeOnDelete();
            $table->string('permission', 80);
            $table->boolean('granted');
            $table->string('reason', 255)->nullable();
            $table->foreignUlid('updated_by')->constrained('users');
            $table->timestampsTz();

            $table->unique(['membership_id', 'permission']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permission_overrides');
    }
};
