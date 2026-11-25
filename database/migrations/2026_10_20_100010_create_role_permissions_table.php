<?php

use App\Domain\Users\Enums\Role;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Droits paramétrables des rôles (RG-ROLE-01 à 06, 05-BACKEND-SCHEMA §2) : sans organisation pour le
 * paramétrage réseau, avec l'organisation pour la surcharge d'un CGA. platform_admin n'y figure pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('organization_id')->nullable()->index()->constrained('organizations')->cascadeOnDelete();
            $table->string('role', 40);
            $table->string('permission', 80);
            $table->boolean('granted');
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        DB::statement("CREATE UNIQUE INDEX role_permissions_unique ON role_permissions (COALESCE(organization_id, ''), role, permission)");

        Constraints::in('role_permissions', 'role', array_values(array_diff(Role::values(), [Role::PlatformAdmin->value])));
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
