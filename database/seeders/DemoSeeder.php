<?php

namespace Database\Seeders;

use App\Domain\Auth\Actions\IssueInvitation;
use App\Domain\Auth\Enums\InvitationChannel;
use App\Domain\Auth\Models\Invitation;
use App\Domain\Closing\Actions\OpenClosingCampaign;
use App\Domain\Concerns\Actions\SubmitConcern;
use App\Domain\Concerns\Enums\ConcernCategory;
use App\Domain\Concerns\Enums\ConcernLevel;
use App\Domain\Concerns\Models\Concern;
use App\Domain\Documents\Samples\SampleDocumentData;
use App\Domain\Dues\Actions\OpenDuesYear;
use App\Domain\Members\Enums\EntityType;
use App\Domain\Members\Enums\FeesPeriodicity;
use App\Domain\Members\Enums\LegalForm;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Support\MemberNumbering;
use App\Domain\Organizations\Enums\AgreementStatus;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Enums\SubscriptionStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Payments\Actions\SyncEntryFee;
use App\Domain\Referentials\Models\RegionalDirectorate;
use App\Domain\Referentials\Models\TaxOffice;
use App\Domain\Trainings\Actions\PublishTraining;
use App\Domain\Trainings\Actions\SaveTraining;
use App\Domain\Trainings\Enums\AudienceType;
use App\Domain\Trainings\Enums\TrainingFormat;
use App\Domain\Trainings\Models\Training;
use App\Domain\Trainings\Support\TrainingData;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Enums\UserStatus;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Support\Config\SettingsService;
use App\Support\Files\StorageService;
use App\Support\Numbering\SequenceService;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

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
 * plateforme · 0700000010 gérant du CGA suspendu (consultation seule, RG-ORG-08). Un ancien adhérent
 * radié du CGA de Bouaké (NCC 9000001T) permet de rejouer le réimport d'identité (RG-ADH-15) ;
 * 0700000011 est l'accès principal en ligne d'un adhérent actif de DC-KNOWING (espace adhérent).
 * La fiche de DC-KNOWING est complète (images de remplacement) : ses documents se produisent ; sa
 * campagne de clôture de l'exercice précédent est ouverte ; une préoccupation de l'adhérent en ligne attend
 * une réponse, une demande du gérant attend celle du CNCGA ; une formation du réseau et une de DC-KNOWING sont
 * publiées.
 */
class DemoSeeder extends Seeder
{
    public function __construct(
        private readonly IssueInvitation $issueInvitation,
        private readonly SettingsService $settings,
        private readonly SequenceService $sequences,
        private readonly MemberNumbering $numbering,
        private readonly SyncEntryFee $entryFee,
        private readonly StorageService $storage,
        private readonly SampleDocumentData $samples,
        private readonly OpenClosingCampaign $openClosing,
        private readonly OpenDuesYear $openDues,
        private readonly SubmitConcern $submitConcern,
        private readonly SaveTraining $saveTraining,
        private readonly PublishTraining $publishTraining,
        private readonly TenantContext $context,
    ) {}

    public function run(): void
    {
        $platform = Organization::query()->where('type', OrganizationType::Platform)->sole();
        $cncga = Organization::query()->where('type', OrganizationType::Cncga)->sole();
        $dck = $this->cga('DC-KNOWING CGA', 'DCK', 'Abidjan', '+2252722445501', 'contact@dcknowing.demo');
        $bouake = $this->cga('CGA Démo Bouaké', 'DEMOB', 'Bouaké', '+2252731630102', 'contact@cga-bouake.demo');
        $suspended = $this->suspended($this->cga('CGA Démo Suspendu', 'DEMOS', 'Korhogo', '+2252722445502', 'contact@cga-suspendu.demo'));
        $this->referentials();
        $this->completeProfile($dck, [
            'legal_form' => 'SARL', 'share_capital' => 5_000_000, 'ncc' => '9900001K', 'rccm' => 'CI-ABJ-2020-B-00001',
            'agreement_number' => 'AGR-DEMO-0001', 'agreement_date' => '2020-01-06', 'address' => 'Plateau, avenue Chardy, immeuble Démo',
            'manager_name' => 'Koffi Gérant', 'expert_name' => 'Yao Multi-rôles', 'signatory_name' => 'Koffi Gérant', 'signatory_title' => 'Le Gérant',
        ]);
        // Fiche du CNCGA, émetteur des reçus et factures de cotisation (étape 6.9) : sans agrément ni expert-comptable.
        $this->completeProfile($cncga, [
            'legal_form' => 'Association', 'share_capital' => 0, 'ncc' => '9900000C', 'rccm' => 'CI-ABJ-2010-W-00001',
            'address' => 'Cocody, Riviera 3, siège du CNCGA', 'city' => 'Abidjan', 'manager_name' => 'Aminata Présidente', 'numbering_abbreviation' => 'CNCGA',
            'signatory_name' => 'Aminata Présidente', 'signatory_title' => 'La Présidente', 'phone_1' => '+2252722445500', 'email' => 'contact@cncga.demo',
        ]);
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
        $this->formerMember($bouake);
        $this->onlineMember($dck);
        $this->closingCampaign($dck);
        $this->concerns($dck);
        $this->trainings($cncga, $dck);
        // Appel de cotisation de l'année, comme dues:open-year le 1er janvier (F-COT-01) : /cncga/cotisations,
        // /cga/cotisation et la collection Postman s'y appuient. Rejouable (une cotisation par centre et par an).
        $this->openDues->execute(now()->year);

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
     * Adhérent actif de DC-KNOWING et son accès principal en ligne (0700000011) : espace adhérent,
     * honoraires, déclaration d'un paiement (F-PAY-03). Son droit d'adhésion reste à payer.
     */
    private function onlineMember(Organization $organization): void
    {
        $member = Tenancy::bypass(fn (): Member => DB::transaction(function () use ($organization): Member {
            $existing = Member::query()->where('organization_id', $organization->id)->where('ncc', '9300001D')->first();
            if ($existing !== null) {
                return $existing;
            }

            $member = new Member([
                'entity_type' => EntityType::Company, 'company_name' => 'ETS DÉMO ADHÉRENT EN LIGNE', 'legal_form' => LegalForm::Sarl,
                'ncc' => '9300001D', 'regime' => TaxRegime::Tee, 'regime_since' => '2026-01-01', 'main_activity' => 'Commerce de détail',
                'address' => 'Plateau, avenue Franchet d\'Espérey', 'phone' => '+2250707000021', 'signatory_name' => 'Aïcha Diabaté',
                'signatory_title' => 'Gérante', 'entry_fee_amount' => 25_000, 'fees_amount' => 15_000, 'fees_periodicity' => FeesPeriodicity::Monthly,
            ]);
            $member->organization_id = $organization->id;
            $member->portfolio = Portfolio::Cga;
            $member->status = MemberStatus::Active;
            $member->adhesion_date = CarbonImmutable::parse('2026-01-15');
            $member->cga_contract_signed_on = CarbonImmutable::parse('2026-01-15');
            $member->validated_at = CarbonImmutable::parse('2026-01-15 10:00:00');
            $member->membership_seq = $this->sequences->next($organization->id, MemberNumbering::MEMBERSHIP);
            $member->membership_number = $this->numbering->membershipNumber($member->membership_seq, 2026, $organization->numbering_abbreviation);
            $member->save();
            $this->entryFee->execute($member);

            return $member;
        }), 'Jeu de démonstration : adhérent en ligne de DC-KNOWING.');

        $user = $this->account('0700000011', 'Aïcha', 'Adhérente', []);
        if (! Membership::query()->where('user_id', $user->id)->where('member_id', $member->id)->exists()) {
            $access = new Membership;
            $access->user_id = $user->id;
            $access->organization_id = $organization->id;
            $access->member_id = $member->id;
            $access->role = Role::MemberOwner;
            $access->status = MembershipStatus::Active;
            $access->activated_at = now();
            $access->save();
        }
    }

    /**
     * Campagne de clôture de l'exercice précédent de DC-KNOWING (F-OBL-08), ouverte comme le ferait
     * closing:open-campaign : /cga/cloture et la collection Postman s'y appuient. Rejouable.
     */
    private function closingCampaign(Organization $organization): void
    {
        Tenancy::bypass(
            fn () => $this->openClosing->execute($organization->id, now()->year - 1, CarbonImmutable::now()),
            'Jeu de démonstration : campagne de clôture de DC-KNOWING.',
        );
    }

    /**
     * Formations publiées (PRD M11) : une du CNCGA pour tous les adhérents et agents du réseau, une de DC-KNOWING
     * pour ses adhérents, dans trois semaines. /cncga/formations, /cga/formations, /adherent/formations et la
     * collection Postman s'y appuient. Rejouable.
     */
    private function trainings(Organization $cncga, Organization $dck): void
    {
        $exists = Tenancy::bypass(static fn (): bool => Training::query()->whereIn('organization_id', [$cncga->id, $dck->id])->exists(), 'Jeu de démonstration : formations.');
        if ($exists) {
            return;
        }

        $start = CarbonImmutable::now()->addWeeks(3)->setTime(9, 0);
        $organizers = [
            [$cncga, Role::CncgaPresident, 'Fiscalité des TEE : nouveautés de l\'année', 30, [AudienceType::AllMembers, AudienceType::CgaStaff]],
            [$dck, Role::CgaManager, 'Tenue de la comptabilité SMT', 15, [AudienceType::AllMembers]],
        ];
        foreach ($organizers as [$organization, $role, $title, $capacity, $audiences]) {
            $organizer = Membership::query()->where('organization_id', $organization->id)->where('role', $role)->firstOrFail();
            $this->context->runAs($organizer, fn (): int => $this->publishTraining->execute($this->saveTraining->create(new TrainingData(
                title: $title, description: 'Session de démonstration.', format: TrainingFormat::InPerson, location: 'Abidjan, Plateau', onlineUrl: null,
                trainer: 'Formateur de démonstration', startsAt: $start, endsAt: $start->setTime(17, 0), capacity: $capacity, price: 0, requiresApproval: false,
                audiences: array_map(static fn (AudienceType $type): array => ['type' => $type, 'regime' => null], $audiences),
            ), $organizer)));
        }
    }

    /**
     * Préoccupations de DC-KNOWING (PRD M10) : une demande de l'adhérent en ligne à son CGA, une demande du
     * gérant au CNCGA, déposées comme dans leur espace. /cga/preoccupations, /cncga/preoccupations et
     * /adherent/messages s'y appuient. Rejouable.
     */
    private function concerns(Organization $organization): void
    {
        $exists = Tenancy::bypass(static fn (): bool => Concern::query()->where('organization_id', $organization->id)->exists(), 'Jeu de démonstration : préoccupations de DC-KNOWING.');
        if ($exists) {
            return;
        }

        $member = Membership::query()->whereHas('user', static fn ($user) => $user->where('phone', '+2250700000011'))->whereNotNull('member_id')->sole();
        $this->context->runAs($member, fn (): Concern => $this->submitConcern->execute(
            ConcernLevel::MemberToCga, ConcernCategory::Tax, 'Montant de la patente', 'Bonjour, quel montant de patente dois-je prévoir cette année ?', [], $member,
        ));

        $manager = Membership::query()->where('organization_id', $organization->id)->where('role', Role::CgaManager)->firstOrFail();
        $this->context->runAs($manager, fn (): Concern => $this->submitConcern->execute(
            ConcernLevel::CgaToCncga, ConcernCategory::Legal, 'Renouvellement de l\'agrément', 'Quelles pièces fournir pour le renouvellement de notre agrément ?', [], $manager,
        ));
    }

    /**
     * Ancien adhérent radié du CGA de Bouaké : DC-KNOWING peut réimporter son identité (RG-ADH-15).
     */
    private function formerMember(Organization $organization): void
    {
        Tenancy::bypass(static function () use ($organization): void {
            if (Member::query()->where('organization_id', $organization->id)->where('ncc', '9000001T')->exists()) {
                return;
            }

            $member = new Member([
                'entity_type' => EntityType::Company, 'company_name' => 'ETS DÉMO ANCIEN ADHÉRENT', 'legal_form' => LegalForm::Sarl,
                'owner_last_name' => 'Konan', 'owner_first_names' => 'Serge', 'ncc' => '9000001T', 'rccm' => 'CI-BKE-2024-B-0001',
                'regime' => TaxRegime::Tee, 'regime_since' => '2025-01-15', 'main_activity' => 'Commerce de détail',
                'address' => 'Bouaké, quartier Commerce', 'phone' => '+2250707000011', 'entry_fee_amount' => 25_000,
                'fees_amount' => 15_000, 'fees_periodicity' => FeesPeriodicity::Monthly,
            ]);
            $member->organization_id = $organization->id;
            $member->portfolio = Portfolio::Cga;
            $member->status = MemberStatus::Radiated;
            $member->membership_number = '2025-DEMOB-0001';
            $member->membership_seq = 1;
            $member->adhesion_date = CarbonImmutable::parse('2025-01-15');
            $member->cga_contract_signed_on = CarbonImmutable::parse('2025-01-15');
            $member->cga_contract_ended_on = CarbonImmutable::parse('2026-06-30');
            $member->validated_at = CarbonImmutable::parse('2025-01-15 10:00:00');
            $member->save();
        }, 'Jeu de démonstration : ancien adhérent du CGA de Bouaké.');
    }

    /**
     * Fiche complète de DC-KNOWING (F-ORG-03, RG-ORG-04) : identité fictive et images de remplacement
     * (logo, signature, cachet), pour que ses documents se produisent dès l'installation (F-DOC-01).
     * Seuls les champs encore vides sont remplis.
     */
    /**
     * @param  array<string, mixed>  $identity  champs de la fiche, posés s'ils sont vides
     */
    private function completeProfile(Organization $organization, array $identity): void
    {
        if ($organization->missingProfileFields() === []) {
            return;
        }

        $office = TaxOffice::query()->where('code', 'DEMO-PLT')->sole();
        $identity += ['regional_directorate_id' => $office->regional_directorate_id, 'tax_office_id' => $office->id];
        foreach ($identity as $field => $value) {
            if (blank($organization->getAttribute($field))) {
                $organization->setAttribute($field, $value);
            }
        }

        $directory = storage_path('framework/documents/demo-'.Str::lower((string) Str::ulid()));
        File::ensureDirectoryExists($directory);
        try {
            $images = $this->samples->images($directory, (string) config('documents.verification_url'));
            foreach (['logo_file_id' => 'cga_logo', 'signature_file_id' => 'cga_signature', 'stamp_file_id' => 'cga_stamp'] as $column => $image) {
                if ($organization->getAttribute($column) === null) {
                    $organization->setAttribute($column, $this->storage->store(File::get($images[$image]), basename($images[$image]), 'branding', $organization->id)->id);
                }
            }
        } finally {
            File::deleteDirectory($directory);
        }

        $organization->profile_completed_at = now()->toImmutable();
        $organization->save();
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
