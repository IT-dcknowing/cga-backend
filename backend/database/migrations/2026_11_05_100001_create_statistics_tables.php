<?php

use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Statistiques (05-BACKEND-SCHEMA §11, 02-TRD §9) : agrégats non nominatifs.
 *
 * - stat_org_daily : un agrégat par CGA abonné et par jour, portefeuille CGA seulement (RG-STA-05) ; stocks
 *   arrêtés le jour (adhérents, régimes, secteurs, communes, chiffre d'affaires de référence) et flux du jour
 *   (adhésions, radiations, honoraires, impôts, préoccupations). Ajout au schéma : concerns_responded (nombre
 *   de premières réponses du jour), pour moyenner les délais d'une période sans moyenne de moyennes, et
 *   computed_at (décision 0015).
 * - stat_network_monthly : vue matérialisée mensuelle du réseau (cotisations, rapports, CGA abonnés et
 *   inscrits au registre), sur tous les CGA (RG-STA-02), recalculée chaque nuit par stats:refresh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stat_org_daily', function (Blueprint $table) {
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->date('day');
            $table->integer('active_members');
            $table->integer('members_rme');
            $table->integer('members_tee');
            $table->integer('members_rsi');
            $table->integer('new_members');
            $table->integer('radiations');
            $table->bigInteger('fees_due');
            $table->bigInteger('fees_paid');
            $table->bigInteger('tax_paid_total');
            $table->jsonb('tax_paid_by_nature');
            $table->bigInteger('declared_revenue');
            $table->jsonb('members_by_sector');
            $table->jsonb('members_by_commune');
            $table->integer('concerns_opened');
            $table->integer('concerns_closed');
            $table->integer('concerns_responded');
            $table->decimal('concerns_avg_response_hours', 10, 2)->nullable();
            $table->timestampTz('computed_at');

            $table->primary(['organization_id', 'day']);
            $table->index('day');
        });
        Constraints::check('stat_org_daily', 'stat_org_daily_counts', 'active_members >= 0 AND members_rme >= 0 AND members_tee >= 0 AND members_rsi >= 0 AND new_members >= 0 AND radiations >= 0 AND concerns_opened >= 0 AND concerns_closed >= 0 AND concerns_responded >= 0');
        Constraints::check('stat_org_daily', 'stat_org_daily_amounts', 'fees_due >= 0 AND fees_paid >= 0 AND tax_paid_total >= 0 AND declared_revenue >= 0');
        Constraints::check('stat_org_daily', 'stat_org_daily_regimes', 'members_rme + members_tee + members_rsi = active_members');

        DB::statement(<<<'SQL'
            CREATE MATERIALIZED VIEW stat_network_monthly AS
            WITH bounds AS (
                SELECT make_date(LEAST(
                    COALESCE((SELECT min(year) FROM network_dues), EXTRACT(YEAR FROM CURRENT_DATE)::int),
                    COALESCE((SELECT min(year) FROM quarterly_reports), EXTRACT(YEAR FROM CURRENT_DATE)::int),
                    EXTRACT(YEAR FROM CURRENT_DATE)::int
                ), 1, 1) AS first_month
            ),
            months AS (
                SELECT series::date AS month, (series + interval '1 month' - interval '1 day')::date AS month_end
                FROM bounds, generate_series(bounds.first_month, date_trunc('month', CURRENT_DATE)::date, interval '1 month') AS series
            )
            SELECT
                months.month,
                months.month_end,
                (SELECT count(*) FROM organizations o
                    WHERE o.type = 'cga' AND o.deleted_at IS NULL AND o.created_at::date <= months.month_end
                    AND NOT EXISTS (SELECT 1 FROM organization_status_events e
                        WHERE e.organization_id = o.id AND e.event = 'terminated' AND e.occurred_at::date <= months.month_end)
                )::int AS total_cgas,
                (SELECT count(*) FROM organizations o
                    WHERE o.type = 'cga' AND o.deleted_at IS NULL AND o.subscription_status <> 'none'
                    AND o.subscription_started_at <= months.month_end
                    AND (o.subscription_ended_at IS NULL OR o.subscription_ended_at > months.month_end)
                )::int AS subscribed_cgas,
                COALESCE((SELECT sum(d.amount_due) FROM network_dues d
                    WHERE d.year = EXTRACT(YEAR FROM months.month)::int), 0)::bigint AS dues_expected,
                COALESCE((SELECT sum(p.amount) FROM network_due_payments p
                    WHERE p.status = 'confirmed' AND p.paid_on BETWEEN months.month AND months.month_end), 0)::bigint AS dues_collected,
                COALESCE((SELECT sum(p.amount) FROM network_due_payments p JOIN network_dues d ON d.id = p.network_due_id
                    WHERE p.status = 'confirmed' AND d.year = EXTRACT(YEAR FROM months.month)::int AND p.paid_on <= months.month_end), 0)::bigint AS dues_collected_year_to_date,
                (SELECT count(*) FROM network_dues d
                    WHERE d.year = EXTRACT(YEAR FROM months.month)::int AND d.status = 'paid')::int AS dues_paid_cgas,
                (SELECT count(*) FROM quarterly_reports r
                    WHERE r.deadline BETWEEN months.month AND months.month_end)::int AS reports_due,
                (SELECT count(*) FROM quarterly_reports r
                    WHERE r.transmitted_at IS NOT NULL AND r.transmitted_at::date BETWEEN months.month AND months.month_end)::int AS reports_transmitted,
                (SELECT count(*) FROM quarterly_reports r
                    WHERE r.transmitted_at IS NOT NULL AND r.transmitted_at::date BETWEEN months.month AND months.month_end
                    AND r.transmitted_at::date > r.deadline)::int AS reports_transmitted_late,
                now() AS computed_at
            FROM months
            SQL);
        DB::statement('CREATE UNIQUE INDEX stat_network_monthly_month ON stat_network_monthly (month)');
    }

    public function down(): void
    {
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS stat_network_monthly');
        Schema::dropIfExists('stat_org_daily');
    }
};
