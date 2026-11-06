<?php

namespace App\Domain\Platform\Actions;

use App\Domain\Concerns\Models\Concern;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\MemberPiece;
use App\Domain\Notifications\Models\MessageCampaign;
use App\Domain\Notifications\Models\MessageLog;
use App\Domain\Organizations\Enums\SubscriptionStatus;
use App\Domain\Organizations\Models\Organization;
use App\Support\Config\SettingsService;
use App\Support\Files\StorageService;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Purges et anonymisations conformes au PRD §7 et RG-ORG-07 :
 * - Dossier d'un adhérent radié : 10 ans après radiation -> anonymisation
 * - Dossier Cabinet clos : 10 ans après clôture -> anonymisation
 * - Documents légaux et factures : 10 ans -> suppression
 * - Préoccupations : 5 ans -> suppression
 * - Messages et notifications : 2 ans -> suppression
 * - Journaux d'activité : 2 ans / audit : 5 ans -> suppression
 * - Données privées d'un CGA résilié : 6 mois après résiliation (data_deletion_due_at) -> suppression
 */
class PurgeRetention
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly StorageService $storage,
    ) {}

    /**
     * @return array{anonymized_members: int, purged_documents: int, purged_concerns: int, purged_messages: int, purged_activity_logs: int, purged_audit_logs: int, purged_terminated_cga_records: int}
     */
    public function execute(?CarbonImmutable $today = null, bool $dryRun = false): array
    {
        $today = $today ?? CarbonImmutable::now();

        return Tenancy::bypass(function () use ($today, $dryRun): array {
            $stats = [
                'anonymized_members' => 0,
                'purged_documents' => 0,
                'purged_concerns' => 0,
                'purged_messages' => 0,
                'purged_activity_logs' => 0,
                'purged_audit_logs' => 0,
                'purged_terminated_cga_records' => 0,
            ];

        // 1. Anonymisation des adhérents radiés ou clos depuis 10 ans
        $tenYearsAgo = $today->subYears(10);
        $membersToAnonymize = Member::query()
            ->whereIn('status', [MemberStatus::Radiated, MemberStatus::Closed])
            ->where('updated_at', '<=', $tenYearsAgo)
            ->where('company_name', '!=', '[Anonymisé]')
            ->get();

        $stats['anonymized_members'] = $membersToAnonymize->count();
        if (! $dryRun) {
            foreach ($membersToAnonymize as $member) {
                DB::transaction(function () use ($member): void {
                    $member->company_name = '[Anonymisé]';
                    $member->sigle = null;
                    $member->owner_last_name = 'Anonymisé';
                    $member->owner_first_names = 'Anonymisé';
                    $member->owner_nationality = null;
                    $member->phone = '+22500000000';
                    $member->whatsapp = null;
                    $member->email = null;
                    $member->address = null;
                    $member->signatory_name = null;
                    $member->save();

                    // Suppression des pièces jointes d'identité
                    MemberPiece::where('member_id', $member->id)->delete();
                });
            }
        }

        // 2. Suppression des documents de plus de 10 ans
        $docsToPurge = GeneratedDocument::query()
            ->where(function ($q) use ($tenYearsAgo): void {
                $q->where('issued_at', '<=', $tenYearsAgo)
                    ->orWhere(function ($sub) use ($tenYearsAgo): void {
                        $sub->whereNull('issued_at')->where('created_at', '<=', $tenYearsAgo);
                    });
            })
            ->with(['pdf', 'docx', 'xlsx'])
            ->get();

        $stats['purged_documents'] = $docsToPurge->count();
        if (! $dryRun) {
            foreach ($docsToPurge as $doc) {
                DB::transaction(function () use ($doc): void {
                    // Suppression des fichiers physiques associés
                    foreach ([$doc->pdf, $doc->docx, $doc->xlsx] as $file) {
                        if ($file) {
                            try {
                                $this->storage->delete($file);
                            } catch (\Throwable) {
                                // Fichier introuvable ignoré
                            }
                        }
                    }
                    $doc->delete();
                });
            }
        }

        // 3. Suppression des préoccupations de plus de 5 ans (5 ans = suppression, PRD §7)
        $fiveYearsAgo = $today->subYears(5);
        $concernsQuery = Concern::query()->where('created_at', '<=', $fiveYearsAgo);
        $stats['purged_concerns'] = $concernsQuery->count();
        if (! $dryRun) {
            $concernsQuery->delete();
        }

        // 4. Suppression des messages et campagnes de plus de 2 ans
        $twoYearsAgo = $today->subYears(2);
        $messagesQuery = MessageLog::query()->where('created_at', '<=', $twoYearsAgo);
        $campaignsQuery = MessageCampaign::query()->where('created_at', '<=', $twoYearsAgo);
        $stats['purged_messages'] = $messagesQuery->count() + $campaignsQuery->count();
        if (! $dryRun) {
            $messagesQuery->delete();
            $campaignsQuery->delete();
        }

        // 5. Suppression des journaux d'activité > 2 ans et audit > 5 ans
        $activityRetentionYears = (int) ($this->settings->get('retention.activity_logs_years') ?? 2);
        $auditRetentionYears = (int) ($this->settings->get('retention.audit_logs_years') ?? 5);

        $activityCutoff = $today->subYears($activityRetentionYears);
        $auditCutoff = $today->subYears($auditRetentionYears);

        $stats['purged_activity_logs'] = DB::table('activity_logs')->where('created_at', '<=', $activityCutoff)->count();
        if (! $dryRun && $stats['purged_activity_logs'] > 0) {
            DB::table('activity_logs')->where('created_at', '<=', $activityCutoff)->delete();
        }

        $stats['purged_audit_logs'] = DB::table('audit_logs')->where('created_at', '<=', $auditCutoff)->count();
        if (! $dryRun && $stats['purged_audit_logs'] > 0) {
            DB::table('audit_logs')->where('created_at', '<=', $auditCutoff)->delete();
        }

        // 6. Purge des données d'un CGA résilié dont l'échéance de rétention est échue (RG-ORG-07, 6 mois après résiliation)
        $terminatedOrgs = Organization::query()
            ->where('subscription_status', SubscriptionStatus::Terminated)
            ->whereNotNull('data_deletion_due_at')
            ->where('data_deletion_due_at', '<=', $today)
            ->get();

        foreach ($terminatedOrgs as $org) {
            $membersCount = Member::query()->where('organization_id', $org->id)->count();
            $stats['purged_terminated_cga_records'] += $membersCount;

            if (! $dryRun) {
                DB::transaction(function () use ($org): void {
                    // Supprime définitivement les données privées du CGA (RG-ORG-07)
                    Member::withTrashed()->where('organization_id', $org->id)->forceDelete();
                    GeneratedDocument::query()->where('organization_id', $org->id)->delete();
                    // L'organisation reste présente pour conserver les cotisations et agrégats CNCGA (RG-ORG-07)
                    $org->data_deletion_due_at = null;
                    $org->save();
                });
            }
        }

            return $stats;
        }, 'Purge hebdomadaire de rétention des données PRD section 7');
    }
}
