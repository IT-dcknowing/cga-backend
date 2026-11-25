<?php

use App\Domain\Announcements\Enums\AnnouncementAudience;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Annonces (05-BACKEND-SCHEMA §14, PRD M12) : publiées par le CNCGA (tous les CGA, certains CGA, tous les
 * adhérents du réseau) ou par un CGA (ses adhérents, filtrés par portefeuille, régime, secteur), cloisonnées à
 * leur auteur ; CGA choisis (target_organization_id : organization_id du schéma, renommé pour ne pas le
 * confondre avec l'auteur, décision 0014) ; lectures par utilisateur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->string('title', 200);
            $table->text('body');
            $table->foreignUlid('attachment_file_id')->nullable()->constrained('files');
            $table->string('audience', 30);
            $table->jsonb('member_filter')->nullable();
            $table->boolean('send_email')->default(false);
            $table->integer('recipients_count')->default(0);
            $table->timestampTz('published_at');
            $table->foreignUlid('created_by')->constrained('users');
            $table->timestampsTz();

            $table->index(['organization_id', 'published_at']);
            $table->index(['audience', 'published_at']);
        });
        Constraints::enum('announcements', 'audience', AnnouncementAudience::class);
        Constraints::check('announcements', 'announcements_member_filter', "(audience = 'own_members') = (member_filter IS NOT NULL)");
        Constraints::check('announcements', 'announcements_recipients', 'recipients_count >= 0');

        Schema::create('announcement_targets', function (Blueprint $table) {
            $table->foreignUlid('announcement_id')->constrained('announcements')->cascadeOnDelete();
            $table->foreignUlid('target_organization_id')->constrained('organizations');

            $table->primary(['announcement_id', 'target_organization_id']);
            $table->index('target_organization_id');
        });

        Schema::create('announcement_reads', function (Blueprint $table) {
            $table->foreignUlid('announcement_id')->constrained('announcements')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users');
            $table->timestampTz('read_at');

            $table->primary(['announcement_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_reads');
        Schema::dropIfExists('announcement_targets');
        Schema::dropIfExists('announcements');
    }
};
