<?php

use App\Domain\Closing\Enums\ClosingCampaignStatus;
use App\Domain\Closing\Enums\ClosingDeliverable;
use App\Domain\Members\Enums\Portfolio;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Campagne de clôture de l'exercice (05-BACKEND-SCHEMA §7, F-OBL-08, RG-OBL-10 à 12) : une campagne par
 * CGA et par exercice, calendrier figé à l'ouverture ; un suivi par dossier actif (portefeuilles CGA et
 * Cabinet), étape par étape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('closing_campaigns', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->smallInteger('fiscal_year');
            $table->jsonb('steps');
            $table->string('status', 10);
            $table->timestampTz('opened_at');
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'fiscal_year']);
        });
        Constraints::enum('closing_campaigns', 'status', ClosingCampaignStatus::class);

        Schema::create('closing_campaign_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->foreignUlid('campaign_id')->constrained('closing_campaigns');
            $table->foreignUlid('member_id')->constrained('members');
            $table->string('portfolio', 10);
            $table->string('deliverable', 30);
            $table->date('step_pieces_at')->nullable();
            $table->date('step_regularisation_at')->nullable();
            $table->date('step_finalisation_at')->nullable();
            $table->date('step_filing_at')->nullable();
            $table->string('filing_reference', 100)->nullable();
            $table->foreignUlid('filing_file_id')->nullable()->constrained('files');
            $table->foreignUlid('assigned_to')->nullable()->constrained('users');
            $table->timestampTz('last_reminder_at')->nullable();
            $table->timestampsTz();

            $table->unique(['campaign_id', 'member_id']);
            $table->index(['organization_id', 'campaign_id']);
        });
        Constraints::enum('closing_campaign_items', 'portfolio', Portfolio::class);
        Constraints::enum('closing_campaign_items', 'deliverable', ClosingDeliverable::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('closing_campaign_items');
        Schema::dropIfExists('closing_campaigns');
    }
};
