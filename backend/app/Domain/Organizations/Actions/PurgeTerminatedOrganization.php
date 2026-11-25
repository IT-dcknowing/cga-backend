<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Concerns\Actions\DeleteConcerns;
use App\Domain\Concerns\Models\Concern;
use App\Domain\Members\Models\MemberPiece;
use App\Domain\Notifications\Models\MessageCampaign;
use App\Domain\Notifications\Models\MessageLog;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Actions\AnonymizeUser;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Enums\UserStatus;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Domain\Users\Permissions\PermissionResolver;
use App\Support\Files\StorageService;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;

/**
 * Données d'un CGA résilié, à l'échéance `data_deletion_due_at` (RG-ORG-07, décision 0018 §3). Supprime ce
 * qu'aucune durée légale du PRD §7 ne couvre :
 *  - comptes d'accès : rattachements des agents et des adhérents, invitations, droits ajustés ; les utilisateurs
 *    sans autre rattachement sont anonymisés. Un rattachement encore cité par une inscription à une formation ou un
 *    export est désactivé au lieu d'être supprimé ;
 *  - préoccupations, échanges et leurs pièces jointes ; historique des messages et envois groupés ; notifications ;
 *  - pièces justificatives des dossiers, avec leurs fichiers ;
 *  - paramètres, droits ajustés et connexion de facturation FNE propres au centre.
 * Restent jusqu'à leur propre échéance (10 ans) puis suivent la purge normale : dossiers des adhérents, contrats,
 * attestations, reçus, factures, rapports trimestriels, obligations et paiements. L'organisation reste pour les
 * agrégats anonymisés et l'historique des cotisations du CNCGA. Le tout dans une transaction, sans recopier de
 * donnée personnelle dans les journaux.
 */
final class PurgeTerminatedOrganization
{
    public function __construct(
        private readonly AnonymizeUser $anonymizeUser,
        private readonly DeleteConcerns $deleteConcerns,
        private readonly StorageService $storage,
        private readonly PermissionResolver $permissions,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{accesses: int, anonymized_users: int, concerns: int, messages: int, notifications: int, pieces: int, settings: int}
     */
    public function execute(Organization $organization): array
    {
        return Tenancy::bypass(fn (): array => DB::transaction(function () use ($organization): array {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);

            $counts = [
                ...$this->accesses($organization),
                'concerns' => $this->concerns($organization),
                'messages' => $this->messages($organization),
                'notifications' => DB::table('notifications')->where('data->organization_id', $organization->id)->delete(),
                'pieces' => $this->pieces($organization),
                'settings' => $this->settings($organization),
            ];

            $organization->data_deletion_due_at = null;
            $organization->save();
            $this->audit->record('organization_data_purged', $organization, context: $counts);

            DB::afterCommit(fn () => $this->permissions->flush());

            return $counts;
        }), 'RG-ORG-07 : purge des données d\'un CGA résilié (décision 0018 §3).');
    }

    /**
     * @return array{accesses: int, anonymized_users: int}
     */
    private function accesses(Organization $organization): array
    {
        $memberships = Membership::query()->where('organization_id', $organization->id)->get(['id', 'user_id']);
        $membershipIds = $memberships->pluck('id')->all();
        $userIds = $memberships->pluck('user_id')->unique()->all();

        DB::table('invitations')->whereIn('membership_id', $membershipIds)->delete();
        DB::table('user_permission_overrides')->where('organization_id', $organization->id)->delete();

        $cited = DB::table('training_registrations')->whereIn('membership_id', $membershipIds)->pluck('membership_id')
            ->merge(DB::table('exports')->whereIn('membership_id', $membershipIds)->pluck('membership_id'))
            ->unique()->all();
        Membership::query()->whereIn('id', $membershipIds)->whereNotIn('id', $cited)->delete();
        Membership::query()->whereIn('id', $cited)->update([
            'status' => MembershipStatus::Disabled->value,
            'disabled_at' => now(),
            'read_only_until' => null,
        ]);

        $anonymized = 0;
        $users = User::query()->whereIn('id', $userIds)->where('status', '!=', UserStatus::Deleted->value)
            ->whereDoesntHave('memberships', static fn ($query) => $query->where('organization_id', '!=', $organization->id))
            ->lockForUpdate()->get();
        foreach ($users as $user) {
            $this->anonymizeUser->execute($user, 'organization_terminated');
            $anonymized++;
        }

        return ['accesses' => count($membershipIds), 'anonymized_users' => $anonymized];
    }

    private function concerns(Organization $organization): int
    {
        /** @var list<string> $ids */
        $ids = Concern::query()->where('organization_id', $organization->id)->pluck('id')->all();

        return $this->deleteConcerns->execute($ids);
    }

    private function messages(Organization $organization): int
    {
        return MessageLog::query()->where('organization_id', $organization->id)->delete()
            + MessageCampaign::query()->where('organization_id', $organization->id)->delete();
    }

    private function pieces(Organization $organization): int
    {
        $fileIds = MemberPiece::query()->where('organization_id', $organization->id)->pluck('file_id');
        $deleted = MemberPiece::query()->where('organization_id', $organization->id)->delete();
        $this->storage->deleteUnreferenced($fileIds);

        return $deleted;
    }

    private function settings(Organization $organization): int
    {
        return DB::table('settings')->where('organization_id', $organization->id)->delete()
            + DB::table('role_permissions')->where('organization_id', $organization->id)->delete()
            + DB::table('invoicing_providers')->where('organization_id', $organization->id)->delete();
    }
}
