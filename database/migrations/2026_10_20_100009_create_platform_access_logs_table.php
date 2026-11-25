<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Accès de l'équipe technique (platform_admin) aux données d'un CGA, toujours journalisés et
 * consultables par ce CGA (RG-SEC-04, 05-BACKEND-SCHEMA §16).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('admin_user_id')->index()->constrained('users');
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->text('reason');
            $table->string('route', 200)->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();

            $table->index(['organization_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_access_logs');
    }
};
