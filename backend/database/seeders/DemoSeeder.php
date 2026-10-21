<?php

namespace Database\Seeders;

use App\Domain\Auth\Actions\IssueInvitation;
use App\Domain\Auth\Enums\InvitationChannel;
use App\Domain\Auth\Models\Invitation;
use App\Domain\Organizations\Enums\AgreementStatus;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Enums\SubscriptionStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Referentials\Models\RegionalDirectorate;
use App\Domain\Referentials\Models\TaxOffice;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Enums\UserStatus;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Support\Config\SettingsService;
use Illuminate\Database\Seeder;

/**
 * Jeu de démonstration (SEED_DEMO=true) : trois CGA fictifs (dont un suspendu), les quatre autres CGA
 * de la maquette (registre provisoire, non abonnés), deux directions régionales et leurs CDI
 * d'exemple, et un compte par cas utile au SPA et à la collection Postman, tous avec le mot de passe cga.seed.demo.password. Rejouable : rien n'est créé
 * deux fois. N'utilise pas les fabriques de test (absentes d'une installation sans --dev).
 *
 * 0700000001 Présidente CNCGA · 0700000002 gérant DC-KNOWING · 0700000003 multi-rôles (CNCGA,
 * DC-KNOWING, Bouaké) · 0700000004 comptable DC-KNOWING · 0700000005 compte désactivé ·
 * 0700000006 invité (lien cga.seed.demo.invitation_token, l'ancien lien révoqué) · 0700000007 profil
 * (profil@cga-connect.demo) · 0700000008 mot de passe oublié · 0700000009 administrateur de la
 * plateforme · 0700000010 gérant du CGA suspendu (consultation seule, RG-ORG-08).
 */
class DemoSeeder extends Seeder
{
    public function __construct(
        private readonly IssueInvitation $issueInvitation,
        private readonly SettingsService $settings,
    ) {}

    public function run(): void
    {
        $platform = Organization::query()->where('type', OrganizationType::Platform)->sole();
        $cncga = Organization::query()->where('type', OrganizationType::Cncga)->sole();
        $dck = $this->cga('DC-KNOWING CGA', 'DCK', 'Abidjan', '+2252722445501', 'contact@dcknowing.demo');
        $bouake = $this->cga('CGA Démo Bouaké', 'DEMOB', 'Bouaké', '+2252731630102', 'contact@cga-bouake.demo');
        $suspended = $this->suspended($this->cga('CGA Démo Suspendu', 'DEMOS', 'Korhogo', '+2252722445502', 'contact@cga-suspendu.demo'));
        $this->referentials();
        $this->mockupRegistry();

        $this->account('0700000001', 'Aminata', 'Présidente', [[$cncga, Role::CncgaPresident]]);
        $this->account('0700000002', 'Koffi', 'Gérant', [[$dck, Role::CgaManager]]);
        $this->account('0700000003', 'Yao', 'Multi-rôles', [
            [$cncga, Role::CncgaManager],
            [$dck, Role::CgaAccountantExpert],
            [$bouake, Role::CgaAccountant],
        ]);
        $this->account('0700000004', 'Adjoua', 'Comptable', [[$dck, Role::CgaAccountant]]);
        $this->account('0700000005', 'Kouassi', 'Désactivé', [[$dck, Role::CgaAccountant]], UserStatus::Disabled);
        $this->account('0700000007', 'Mariam', 'Profil', [[$bouake, Role::CgaTrainer]], email: 'profil@cga-connect.demo');
        $this->account('0700000008', 'Ibrahim', 'Oubli', [[$dck, Role::CgaLegal]], email: 'motdepasse@cga-connect.demo');
        $this->account('0700000009', 'Awa', 'Administratrice', [[$platform, Role::PlatformAdmin]]);
        $this->account('0700000010', 'Salif', 'Suspendu', [[$suspended, Role::CgaManager]]);
        $this->invitee($dck);

        // Fenêtre des demandes de code ramenée à une minute : la collection Postman se rejoue.
        $this->settings->set('rate_limit.otp', ['max_attempts' => 3, 'decay_minutes' => (int) config('cga.seed.demo.otp_decay_minutes')]);
    }

    private function cga(string $name, string $abbreviation, string $city, string $phone, string $email): Organization
    {
        $existing = Organization::query()->where('numbering_abbreviation', $abbreviation)->first();
        if ($existing !== null) {
            return $existing;
        }

        $organization = new Organization(['name' => $name, 'sigle' => $abbreviation, 'city' => $city, 'phone_1' => $phone, 'email' => $email]);
        $organization->type = OrganizationType::Cga;
        $organization->numbering_abbreviation = $abbreviation;
        $organization->agreement_status = AgreementStatus::Granted;
        $organization->subscription_status = SubscriptionStatus::Active;
        $organization->subscription_started_at = now()->startOfYear();
        $organization->save();

        return $organization;
    }

    /**
     * CGA suspendu à sa création par le jeu de démonstration (F-ORG-06) ; le CNCGA peut ensuite le
     * réactiver à l'écran.
     */
    private function suspended(Organization $organization): Organization
    {
        if ($organization->wasRecentlyCreated) {
            $organization->status = OrganizationStatus::Suspended;
            $organization->suspended_at = now();
            $organization->suspension_reason = 'Suspension de démonstration';
            $organization->save();
        }

        return $organization;
    }

    /**
     * Autres CGA de la maquette, au registre provisoire (RG-DATA-01) : non abonnés, sans compte ;
     * remplacés par la liste réelle depuis « Données de référence ».
     */
    private function mockupRegistry(): void
    {
        $registry = [
            ['CGA EXCELLENCE', 'Abidjan – Plateau', '301/SEPMBPE/DGI'],
            ['CGA IVOIRE GESTION', 'Bouaké', '287/SEPMBPE/DGI'],
            ['CGA SAN PEDRO CONSEIL', 'San Pedro', '312/SEPMBPE/DGI'],
            ['CGA YAMOUSSOUKRO PME', 'Yamoussoukro', '295/SEPMBPE/DGI'],
        ];

        foreach ($registry as [$name, $city, $agreement]) {
            if (Organization::query()->where('agreement_number', $agreement)->exists()) {
                continue;
            }

            $organization = new Organization(['name' => $name, 'city' => $city, 'agreement_number' => $agreement, 'agreement_date' => '2018-01-01', 'phone_1' => '+2252722445566']);
            $organization->type = OrganizationType::Cga;
            $organization->agreement_status = AgreementStatus::Granted;
            $organization->save();
        }
    }

    /**
     * Directions régionales et CDI d'exemple, en données provisoires (RG-DATA-01) : de quoi compléter
     * la fiche d'un CGA (F-ORG-03).
     */
    private function referentials(): void
    {
        $directorates = [
            'DEMO-ABJ' => ['DR Abidjan (démo)', 'DEMO-PLT', 'CDI Plateau (démo)'],
            'DEMO-BKE' => ['DR Bouaké (démo)', 'DEMO-BKE', 'CDI Bouaké (démo)'],
        ];

        foreach ($directorates as $code => [$name, $officeCode, $officeName]) {
            $directorate = RegionalDirectorate::query()->firstOrCreate(['code' => $code], ['name' => $name]);
            TaxOffice::query()->firstOrCreate(['code' => $officeCode], ['regional_directorate_id' => $directorate->id, 'name' => $officeName]);
        }
    }

    /**
     * @param  list<array{0: Organization, 1: Role}>  $spaces
     */
    private function account(string $phone, string $firstName, string $lastName, array $spaces, UserStatus $status = UserStatus::Active, ?string $email = null): User
    {
        $user = $this->user($phone, $firstName, $lastName, $email);
        if ($user->wasRecentlyCreated) {
            $user->password = (string) config('cga.seed.demo.password');
            $user->status = $status;
            $user->phone_verified_at = now();
            $user->save();
        }

        foreach ($spaces as [$organization, $role]) {
            $this->membership($user, $organization, $role, MembershipStatus::Active);
        }

        return $user;
    }

    /**
     * Invité qui n'a pas encore activé son accès : son lien porte le jeton de démonstration.
     */
    private function invitee(Organization $organization): void
    {
        $user = $this->user('0700000006', 'Fatou', 'Invitée', null);
        $membership = $this->membership($user, $organization, Role::CgaSecretary, MembershipStatus::Invited);

        if ($membership->status !== MembershipStatus::Invited) {
            return;
        }

        // Un premier lien, puis un renvoi qui le révoque ; un nouveau passage du seeder réarme le lien
        // de démonstration pour 7 jours.
        $revoked = (string) config('cga.seed.demo.revoked_invitation_token');
        $token = (string) config('cga.seed.demo.invitation_token');
        Invitation::query()->whereIn('token_hash', [Invitation::fingerprint($revoked), Invitation::fingerprint($token)])->get()->each->delete();
        $this->issueInvitation->execute($membership, InvitationChannel::Sms, $revoked);
        $this->issueInvitation->execute($membership, InvitationChannel::Sms, $token);
    }

    private function user(string $nationalPhone, string $firstName, string $lastName, ?string $email): User
    {
        $phone = '+225'.$nationalPhone;

        return User::query()->where('phone', $phone)->first()
            ?? User::query()->create(['first_name' => $firstName, 'last_name' => $lastName, 'phone' => $phone, 'email' => $email]);
    }

    private function membership(User $user, Organization $organization, Role $role, MembershipStatus $status): Membership
    {
        $existing = Membership::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organization->id)
            ->where('role', $role)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $membership = new Membership;
        $membership->user_id = $user->id;
        $membership->organization_id = $organization->id;
        $membership->role = $role;
        $membership->status = $status;
        $membership->activated_at = $status === MembershipStatus::Active ? now() : null;
        $membership->save();

        return $membership;
    }
}
