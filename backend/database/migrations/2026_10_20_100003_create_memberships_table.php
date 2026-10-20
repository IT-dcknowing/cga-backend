<?php

use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Enums\Role;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Rattachement d'un utilisateur à un espace, un rôle par ligne (RG-ACC-02, 05-BACKEND-SCHEMA §2).
 * La clé étrangère member_id → members arrive avec la table des adhérents (étape 2.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->index()->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->ulid('member_id')->nullable()->index();
            $table->string('role', 40);
            $table->string('status', 20)->default(MembershipStatus::Invited->value);
            $table->date('read_only_until')->nullable();
            $table->foreignUlid('invited_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('disabled_at')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'role']);
        });

        DB::statement("CREATE UNIQUE INDEX memberships_unique_role ON memberships (user_id, organization_id, role, COALESCE(member_id, ''))");
        Constraints::enum('memberships', 'role', Role::class);
        Constraints::enum('memberships', 'status', MembershipStatus::class);
        // Un accès adhérent porte toujours son adhérent, et seulement lui.
        Constraints::check('memberships', 'memberships_member_role_has_member', "(left(role, 7) = 'member_') = (member_id IS NOT NULL)");

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('last_space_membership_id')->references('id')->on('memberships')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['last_space_membership_id']);
        });
        Schema::dropIfExists('memberships');
    }
};
