<?php

namespace App\Domain\Platform\Actions;

use App\Domain\Concerns\Actions\DeleteConcerns;
use App\Domain\Concerns\Models\Concern;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Members\Actions\AnonymizeMember;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\RadiationStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Notifications\Models\MessageCampaign;
use App\Domain\Notifications\Models\MessageLog;
use App\Domain\Organizations\Actions\PurgeTerminatedOrganization;
use App\Domain\Organizations\Enums\SubscriptionStatus;
use App\Domain\Organizations\Models\Organization;
use App\Support\Config\SettingsService;
use App\Support\Files\StorageService;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Purges et anonymisations du PRD §7 (tâche hebdomadaire `retention:purge`), durées lues dans `retention.*` :
 * - dossier radié ou clos : anonymisé 10 ans après la date d'effet de sa radiation ;
 * - contrats, attestations, reçus et factures : supprimés après 10 ans. Les rapports trimestriels et les autres
 *   documents ne sont jamais supprimés ici (archivage, décision 0018 §4) ;
 * - préoccupations : supprimées 5 ans après leur création ; messages : supprimés après 2 ans ;
 * - journaux d'activité (2 ans) et d'audit (5 ans) ;
 * - CGA résilié : à `data_deletion_due_at`, seules les données sans durée légale partent (RG-ORG-07, décision 0018 §3).
 * Chaque élément est traité dans sa propre transaction ; une erreur arrête la tâche, qui la journalise.
 */
class PurgeRetention
{
    /**
     * PRD §7 : « Contrats, attestations, reçus, factures (avec leurs éléments de certification FNE) — 10 ans — Suppression ».
     */
    private const LEGAL_DOCUMENT_TYPES = [
        DocumentType::MembershipContract,
        DocumentType::MembershipCertificate,
        DocumentType::TrainingCertificate,
        DocumentType::Receipt,
        DocumentType::DuesReceipt,
        DocumentType::TrainingReceipt,
        DocumentType::Invoice,
        DocumentType::CreditNote,
        DocumentType::DuesInvoice,
    ];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly StorageService $storage,
        private readonly AnonymizeMember $anonymizeMember,
        private readonly DeleteConcerns $deleteConcerns,
        private readonly PurgeTerminatedOrganization $purgeOrganization,
    ) {}

    /**
     * @return array{anonymized_members: int, purged_documents: int, purged_concerns: int, purged_messages: int, purged_activity_logs: int, purged_audit_logs: int, purged_terminated_organizations: int}
     */
    public function execute(?CarbonImmutable $today = null, bool $dryRun = false): array
    {
        $today ??= CarbonImmutable::now();

        return Tenancy::bypass(fn (): array => [
            'anonymized_members' => $this->anonymizeMembers($today, $dryRun),
            'purged_documents' => $this->purgeLegalDocuments($today, $dryRun),
            'purged_concerns' => $this->purgeConcerns($today, $dryRun),
            'purged_messages' => $this->purgeMessages($today, $dryRun),
            'purged_activity_logs' => $this->purgeLogs('activity_logs', 'retention.activity_logs_years', $today, $dryRun),
            'purged_audit_logs' => $this->purgeLogs('audit_logs', 'retention.audit_logs_years', $today, $dryRun),
            'purged_terminated_organizations' => $this->purgeTerminatedOrganizations($today, $dryRun),
        ], 'Purge hebdomadaire de rétention des données PRD section 7');
    }

    private function anonymizeMembers(CarbonImmutable $today, bool $dryRun): int
    {
        $cutoff = $this->cutoff($today, 'retention.radiated_member_years')->toDateString();
        $validated = static fn (Builder $radiation): Builder => $radiation->where('status', RadiationStatus::Validated);
        $query = Member::query()
            ->whereIn('status', [MemberStatus::Radiated, MemberStatus::Closed])
            ->whereNull('anonymized_at')
            ->whereHas('radiations', static fn (Builder $radiation): Builder => $validated($radiation)->whereDate('effective_date', '<=', $cutoff))
            ->whereDoesntHave('radiations', static fn (Builder $radiation): Builder => $validated($radiation)->whereDate('effective_date', '>', $cutoff));

        if ($dryRun) {
            return $query->count();
        }

        $count = 0;
        $query->chunkById(100, function (Collection $members) use (&$count): void {
            /** @var Collection<int, Member> $members */
            foreach ($members as $member) {
                DB::transaction(fn () => $this->anonymizeMember->execute($member));
                $count++;
            }
        });

        return $count;
    }

    private function purgeLegalDocuments(CarbonImmutable $today, bool $dryRun): int
    {
        $cutoff = $this->cutoff($today, 'retention.legal_documents_years');
        $query = GeneratedDocument::query()
            ->whereIn('type', self::LEGAL_DOCUMENT_TYPES)
            ->where(static fn (Builder $date): Builder => $date->where('issued_at', '<=', $cutoff)
                ->orWhere(static fn (Builder $draft): Builder => $draft->whereNull('issued_at')->where('created_at', '<=', $cutoff)));

        if ($dryRun) {
            return $query->count();
        }

        $count = 0;
        $query->chunkById(100, function (Collection $documents) use (&$count): void {
            /** @var Collection<int, GeneratedDocument> $documents */
            foreach ($documents as $document) {
                DB::transaction(fn () => $this->deleteDocument($document));
                $count++;
            }
        });

        return $count;
    }

    /**
     * Les données qui citent le document (paiements, factures, inscriptions, versions) gardent leur ligne sans lui.
     */
    private function deleteDocument(GeneratedDocument $document): void
    {
        $id = $document->id;
        DB::table('generated_documents')->where('replaces_document_id', $id)->update(['replaces_document_id' => null]);
        DB::table('document_verifications')->where('document_id', $id)->delete();
        DB::table('member_payments')->where('receipt_document_id', $id)->update(['receipt_document_id' => null]);
        DB::table('network_due_payments')->where('receipt_document_id', $id)->update(['receipt_document_id' => null]);
        DB::table('invoices')->where('document_id', $id)->update(['document_id' => null]);
        DB::table('training_registrations')->where('receipt_document_id', $id)->update(['receipt_document_id' => null]);
        DB::table('training_registrations')->where('certificate_document_id', $id)->update(['certificate_document_id' => null]);
        DB::table('generated_documents')->where('id', $id)->delete();

        $this->storage->deleteUnreferenced([$document->docx_file_id, $document->pdf_file_id, $document->xlsx_file_id]);
    }

    private function purgeConcerns(CarbonImmutable $today, bool $dryRun): int
    {
        $query = Concern::query()->where('created_at', '<=', $this->cutoff($today, 'retention.concerns_years'));
        if ($dryRun) {
            return $query->count();
        }

        /** @var list<string> $ids */
        $ids = $query->pluck('id')->all();

        return DB::transaction(fn (): int => $this->deleteConcerns->execute($ids));
    }

    private function purgeMessages(CarbonImmutable $today, bool $dryRun): int
    {
        $cutoff = $this->cutoff($today, 'retention.messages_years');
        $messages = MessageLog::query()->where('created_at', '<=', $cutoff);
        $campaigns = MessageCampaign::query()->where('created_at', '<=', $cutoff);

        return $dryRun ? $messages->count() + $campaigns->count() : $messages->delete() + $campaigns->delete();
    }

    private function purgeLogs(string $table, string $setting, CarbonImmutable $today, bool $dryRun): int
    {
        $query = DB::table($table)->where('created_at', '<=', $this->cutoff($today, $setting));

        return $dryRun ? $query->count() : $query->delete();
    }

    private function purgeTerminatedOrganizations(CarbonImmutable $today, bool $dryRun): int
    {
        $due = Organization::query()
            ->where('subscription_status', SubscriptionStatus::Terminated)
            ->whereNotNull('data_deletion_due_at')
            ->whereDate('data_deletion_due_at', '<=', $today->toDateString())
            ->get();

        if (! $dryRun) {
            foreach ($due as $organization) {
                $this->purgeOrganization->execute($organization);
            }
        }

        return $due->count();
    }

    private function cutoff(CarbonImmutable $today, string $setting): CarbonImmutable
    {
        return $today->subYears((int) $this->settings->get($setting));
    }
}
