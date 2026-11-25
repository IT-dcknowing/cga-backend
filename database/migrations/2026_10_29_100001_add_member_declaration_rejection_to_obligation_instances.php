<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Motif du dernier rejet, par le CGA, d'une déclaration de l'adhérent (RG-OBL-09, décision 0004) : la
 * ligne revient « à faire » ou « à payer » et l'adhérent voit pourquoi. Effacé à la déclaration suivante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('obligation_instances', function (Blueprint $table) {
            $table->text('member_declaration_rejection')->nullable()->after('member_declared_at');
        });
    }

    public function down(): void
    {
        Schema::table('obligation_instances', function (Blueprint $table) {
            $table->dropColumn('member_declaration_rejection');
        });
    }
};
