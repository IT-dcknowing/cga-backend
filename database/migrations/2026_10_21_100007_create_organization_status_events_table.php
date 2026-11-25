<?php

use App\Domain\Organizations\Enums\OrganizationStatusEvent;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Historique des suspensions, réactivations, retraits d'agrément et changements d'abonnement d'un CGA
 * (F-ORG-05 à 07, 05-BACKEND-SCHEMA §3) : motif obligatoire, auteur et date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('organization_id')->index()->constrained('organizations')->cascadeOnDelete();
            $table->string('event', 30);
            $table->text('reason');
            $table->foreignUlid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('occurred_at');
        });
        Constraints::enum('organization_status_events', 'event', OrganizationStatusEvent::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_status_events');
    }
};
