<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Une seule radiation en cours par dossier (décision 0003) : un dossier basculé du portefeuille CGA
 * vers le Cabinet par une radiation validée peut revenir au portefeuille CGA (RG-PTF-03), puis être de
 * nouveau radié. L'index « une radiation non annulée par dossier » l'interdisait ; il ne porte plus
 * que sur les demandes en cours.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX radiations_member_current_unique');
        DB::statement("CREATE UNIQUE INDEX radiations_member_pending_unique ON radiations (member_id) WHERE status = 'requested'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX radiations_member_pending_unique');
        DB::statement('CREATE UNIQUE INDEX radiations_member_current_unique ON radiations (member_id) WHERE cancelled_at IS NULL');
    }
};
