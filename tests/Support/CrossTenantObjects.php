<?php

namespace Tests\Support;

use App\Domain\Announcements\Models\Announcement;
use App\Domain\Closing\Models\ClosingCampaign;
use App\Domain\Closing\Models\ClosingCampaignItem;
use App\Domain\Concerns\Models\Concern;
use App\Domain\Documents\Models\DocumentTemplate;
use App\Domain\Documents\Models\File;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Dues\Models\NetworkDue;
use App\Domain\Exports\Models\Export;
use App\Domain\Imports\Enums\ImportStatus;
use App\Domain\Imports\Enums\ImportType;
use App\Domain\Imports\Models\ImportBatch;
use App\Domain\Members\Models\BusinessReferrer;
use App\Domain\Members\Models\Member;
use App\Domain\Notifications\Models\MessageCampaign;
use App\Domain\Notifications\Models\MessagingProvider;
use App\Domain\Obligations\Models\CenterObligation;
use App\Domain\Obligations\Models\MemberObligation;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Domain\Obligations\Models\ObligationType;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Payments\Enums\CertificationMode;
use App\Domain\Payments\Enums\InvoiceStatus;
use App\Domain\Payments\Enums\InvoiceType;
use App\Domain\Payments\Models\Invoice;
use App\Domain\Payments\Models\MemberPayment;
use App\Domain\Platform\Models\FailedJob;
use App\Domain\Referentials\Models\Commune;
use App\Domain\Referentials\Models\Holiday;
use App\Domain\Referentials\Models\NationalReference;
use App\Domain\Referentials\Models\RegionalDirectorate;
use App\Domain\Referentials\Models\Sector;
use App\Domain\Referentials\Models\TaxOffice;
use App\Domain\Reports\Models\CgaPerson;
use App\Domain\Reports\Models\QuarterlyReport;
use App\Domain\Trainings\Enums\RegistrationStatus;
use App\Domain\Trainings\Models\Training;
use App\Domain\Trainings\Models\TrainingRegistration;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\Tenancy;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Objets d'une autre organisation pour le test de cloisonnement (02-TRD §6.3) : pour chaque modèle
 * attendu par une route, comment en créer un qui appartient au CGA B. Chaque nouveau modèle lié à
 * une route /api/v1 doit être ajouté ici, sinon le test de cloisonnement échoue.
 */
final class CrossTenantObjects
{
    /**
     * @return array<class-string<Model>, Closure(Organization): Model>
     */
    public static function builders(): array
    {
        return [
            File::class => static function (Organization $other): File {
                $file = new File;
                $file->organization_id = $other->id;
                $file->disk = 'private';
                $file->path = 'orgs/'.$other->id.'/documents/'.now()->format('Y').'/'.strtolower((string) Str::ulid()).'.pdf';
                $file->original_name = 'contrat-du-cga-b.pdf';
                $file->mime_type = 'application/pdf';
                $file->size_bytes = 1024;
                $file->sha256 = hash('sha256', 'cga-b');
                $file->save();

                return $file;
            },
            Organization::class => static fn (Organization $other): Organization => $other,
            Member::class => static fn (Organization $other): Member => Member::factory()->create(['organization_id' => $other->id]),
            BusinessReferrer::class => static fn (Organization $other): BusinessReferrer => BusinessReferrer::factory()->create(['organization_id' => $other->id]),
            // Obligation locale du CGA B (RG-OBL-01) : le catalogue national n'est pas cloisonné, mais une
            // obligation locale n'appartient qu'à son centre.
            ObligationType::class => static fn (Organization $other): ObligationType => ObligationType::factory()->localTo($other)->create(),
            CenterObligation::class => static fn (Organization $other): CenterObligation => CenterObligation::factory()->create(['organization_id' => $other->id]),
            // Une campagne par CGA et par exercice : le dossier suivi reprend la campagne du CGA B si elle existe.
            ClosingCampaign::class => static fn (Organization $other): ClosingCampaign => self::closingCampaignOf($other),
            ClosingCampaignItem::class => static fn (Organization $other): ClosingCampaignItem => ClosingCampaignItem::factory()->create(['campaign_id' => self::closingCampaignOf($other)->id]),
            // Annonce du CGA B à ses adhérents (PRD M12).
            Announcement::class => static fn (Organization $other): Announcement => Announcement::factory()->create(['organization_id' => $other->id]),
            // Export demandé par un agent du CGA B (F-EXP-01).
            Export::class => static fn (Organization $other): Export => Export::factory()->create(['membership_id' => Membership::factory()->role(Role::CgaManager)->create(['organization_id' => $other->id])->id]),
            // Formation organisée par le CGA B et inscription d'un de ses agents (PRD M11).
            Training::class => static fn (Organization $other): Training => Training::factory()->create(['organization_id' => $other->id]),
            TrainingRegistration::class => static fn (Organization $other): TrainingRegistration => Tenancy::bypass(static function () use ($other): TrainingRegistration {
                $agent = Membership::factory()->create(['organization_id' => $other->id]);
                $registration = new TrainingRegistration;
                $registration->forceFill([
                    'organization_id' => $other->id, 'training_id' => Training::factory()->create(['organization_id' => $other->id])->id,
                    'membership_id' => $agent->id, 'user_id' => $agent->user_id, 'registered_by' => $agent->user_id, 'status' => RegistrationStatus::Registered,
                ])->save();

                return $registration;
            }, 'Test de cloisonnement : inscription du CGA B.'),
            // Demande d'un adhérent du CGA B à son centre (PRD M10).
            Concern::class => static fn (Organization $other): Concern => Concern::factory()->create(['organization_id' => $other->id]),
            CgaPerson::class => static fn (Organization $other): CgaPerson => CgaPerson::factory()->create(['organization_id' => $other->id]),
            MessageCampaign::class => static fn (Organization $other): MessageCampaign => Tenancy::bypass(static function () use ($other): MessageCampaign {
                $campaign = new MessageCampaign;
                $campaign->forceFill(['organization_id' => $other->id, 'title' => 'Rappel', 'body' => 'Texte', 'channels' => ['platform'], 'recipient_filter' => [], 'recipients_count' => 0, 'status' => 'draft', 'requested_by' => User::factory()->create()->id])->save();

                return $campaign;
            }, 'Test de cloisonnement : envoi groupé du CGA B.'),
            // Une cotisation par CGA et par année : réutilisée si elle existe.
            NetworkDue::class => static fn (Organization $other): NetworkDue => Tenancy::bypass(static fn (): ?NetworkDue => NetworkDue::query()->where('organization_id', $other->id)->first(), 'Test de cloisonnement : cotisation du CGA B.')
                ?? NetworkDue::factory()->create(['organization_id' => $other->id]),
            // Un rapport par CGA et par trimestre : réutilisé s'il existe.
            QuarterlyReport::class => static fn (Organization $other): QuarterlyReport => Tenancy::bypass(static fn (): ?QuarterlyReport => QuarterlyReport::query()->where('organization_id', $other->id)->first(), 'Test de cloisonnement : rapport du CGA B.')
                ?? QuarterlyReport::factory()->create(['organization_id' => $other->id]),
            ObligationInstance::class => static fn (Organization $other): ObligationInstance => ObligationInstance::factory()->create([
                'member_obligation_id' => MemberObligation::factory()->create(['member_id' => Member::factory()->active()->create(['organization_id' => $other->id])->id])->id,
            ]),
            GeneratedDocument::class => static fn (Organization $other): GeneratedDocument => GeneratedDocument::factory()->create(['member_id' => Member::factory()->active()->create(['organization_id' => $other->id])->id]),
            MemberPayment::class => static fn (Organization $other): MemberPayment => MemberPayment::factory()->create(['member_id' => Member::factory()->active()->create(['organization_id' => $other->id])->id]),
            Membership::class => static fn (Organization $other): Membership => Membership::factory()->role(Role::MemberOwner)->create(['organization_id' => $other->id]),
            User::class => static fn (Organization $other): User => Membership::factory()->create(['organization_id' => $other->id])->user,
            Invoice::class => static fn (Organization $other): Invoice => Tenancy::bypass(static function () use ($other): Invoice {
                $invoice = new Invoice;
                $invoice->organization_id = $other->id;
                $invoice->type = InvoiceType::Invoice;
                $invoice->member_id = Member::factory()->active()->create(['organization_id' => $other->id])->id;
                $invoice->number = 'FAC-B-'.Str::random(6);
                $invoice->issue_date = today()->toImmutable();
                $invoice->lines = [];
                $invoice->amount_excl_tax = 0;
                $invoice->tax_amount = 0;
                $invoice->amount_incl_tax = 0;
                $invoice->status = InvoiceStatus::Rejected;
                $invoice->certification_mode = CertificationMode::Manual;
                $invoice->created_by = Membership::factory()->create(['organization_id' => $other->id])->user_id;
                $invoice->save();

                return $invoice;
            }, 'Test de cloisonnement : facture du CGA B.'),
            ImportBatch::class => static fn (Organization $other): ImportBatch => Tenancy::bypass(static function () use ($other): ImportBatch {
                $batch = new ImportBatch;
                $batch->organization_id = $other->id;
                $batch->type = ImportType::Cgas;
                $batch->file_id = (string) self::builders()[File::class]($other)->getKey();
                $batch->status = ImportStatus::Done;
                $batch->save();

                return $batch;
            }, 'Test de cloisonnement : lot d\'import du CGA B.'),
        ];
    }

    /**
     * Modèles nationaux, sans organisation (05-BACKEND-SCHEMA §4 : référentiels lisibles par tous) : leurs
     * routes ne relèvent pas du cloisonnement entre CGA. Un modèle cloisonné ne peut pas figurer ici
     * (vérifié par le test de cloisonnement).
     *
     * @return array<class-string<Model>, string> modèle => raison
     */
    public static function globalModels(): array
    {
        $referential = 'Référentiel national géré par le CNCGA, lisible dans tout espace (05-BACKEND-SCHEMA §4).';

        return [
            RegionalDirectorate::class => $referential,
            TaxOffice::class => $referential,
            Commune::class => $referential,
            Sector::class => 'Référentiel national ; un secteur proposé n\'est visible que du CGA qui l\'a proposé (RG-REF-01, test dédié).',
            Holiday::class => $referential,
            NationalReference::class => $referential,
            DocumentTemplate::class => 'Modèle de document du réseau, commun à tous les centres, géré par l\'administrateur de la plateforme (F-ADM-02).',
            MessagingProvider::class => 'Compte d\'envoi de la plateforme (écran « Envois », RG-NOT-05), géré par l\'administrateur seul ; un centre reçoit 403.',
            FailedJob::class => 'Travail en échec des files de la plateforme (écran « Technique », 02-TRD §12.3), géré par l\'administrateur seul ; un centre reçoit 403.',
        ];
    }

    /**
     * Paramètres de route qui ne désignent pas un objet d'une organisation, avec leur justification.
     *
     * @return array<string, string> « nom.de.route:paramètre » => raison
     */
    public static function nonTenantParameters(): array
    {
        $invitationToken = 'Jeton secret du lien d\'invitation (route publique, F-AUTH-02) : il désigne l\'invitation sans identifiant ; un jeton inconnu répond 404.';

        $dataset = 'Jeu de données de référence (valeur de la liste des jeux) : la route ne désigne aucun objet d\'une organisation ; un jeu inconnu répond 404.';
        $registrationId = 'Inscription cherchée parmi celles de la formation {training}, elle-même cherchée dans le centre de l\'espace actif ; une autre répond 404.';
        $networkTraining = 'Formation du réseau (CNCGA) lue par un CGA ; une formation d\'un autre CGA ou un brouillon répond 404.';
        $role = 'Rôle de la plateforme (valeur de la liste des rôles) : la route ne désigne aucun objet d\'une organisation ; un rôle inconnu répond 404.';

        return [
            'network.roles.show:role' => $role,
            'network.roles.update:role' => $role,
            'network.roles.reset:role' => $role,
            'network.roles.impact:role' => $role,
            'cga.roles.show:role' => $role,
            'cga.roles.update:role' => $role,
            'cga.roles.reset:role' => $role,
            'cga.roles.impact:role' => $role,
            'reference-data.template:dataset' => $dataset,
            'reference-data.preview:dataset' => $dataset,
            'reference-data.apply:dataset' => $dataset,
            'reference-data.validate:dataset' => $dataset,
            'cga.closing-campaigns.bulk.step:step' => 'Étape de la campagne de clôture (valeur de la liste des étapes, F-OBL-08) : elle ne désigne aucun objet, les dossiers sont cherchés dans {closingCampaign} ; une valeur inconnue répond 404.',
            'cga.closing-campaigns.items.step:step' => 'Étape de la campagne de clôture (valeur de la liste des étapes, F-OBL-08) : elle ne désigne aucun objet, le dossier est contrôlé par {closingCampaign} et {item} ; une valeur inconnue répond 404.',
            'network.obligation-catalog.validate:version' => 'Version du catalogue national des obligations (F-OBL-01) : commune à tout le réseau, elle ne désigne aucun objet d\'une organisation ; une version inconnue répond 404.',
            'auth.invitations.show:invitationToken' => $invitationToken,
            'auth.invitations.accept:invitationToken' => $invitationToken,
            'public.verify:verificationCode' => 'Code aléatoire du QR d\'un document (page publique, F-DOC-04) : il désigne le document sans identifiant ; un code inconnu répond 404.',
            'cga.members.pieces.store:pieceType' => 'Type de pièce du dossier (F-PJ-01) : il ne désigne aucun objet, le dossier est contrôlé par {member} ; une valeur inconnue répond 404.',
            'network.settings.update:settingKey' => 'Clé du registre des paramètres (config/cga.php) : la route ne désigne aucun objet d\'un centre ; une clé inconnue de l\'espace répond 404.',
            'network.settings.reset:settingKey' => 'Clé du registre des paramètres (config/cga.php) : la route ne désigne aucun objet d\'un centre ; une clé inconnue de l\'espace répond 404.',
            'platform.settings.update:settingKey' => 'Clé du registre des paramètres (config/cga.php) : la route ne désigne aucun objet d\'un centre ; une clé inconnue de l\'espace répond 404.',
            'platform.settings.reset:settingKey' => 'Clé du registre des paramètres (config/cga.php) : la route ne désigne aucun objet d\'un centre ; une clé inconnue de l\'espace répond 404.',
            'network.profile.images.store:kind' => 'Type d\'image de la fiche du CNCGA de l\'espace actif : la route ne désigne aucun objet, une valeur inconnue répond 404.',
            'cga.profile.images.store:kind' => 'Type d\'image (logo, signature, cachet) de la fiche du CGA de l\'espace actif : la route ne désigne aucun objet, une valeur inconnue répond 404.',
            'network.dues.show:dueId' => 'Cotisation lue par le CNCGA, qui voit celles de tous les centres (05-BACKEND-SCHEMA §10) ; un CGA reçoit 403 (Policy).',
            'network.dues.payments.record:dueId' => 'Cotisation d\'un centre, saisie par le CNCGA seul ; un CGA reçoit 403 (Policy).',
            'network.dues.invoice:dueId' => 'Cotisation d\'un centre, facturée par le CNCGA seul ; un CGA reçoit 403 (Policy).',
            'network.dues.remind:dueId' => 'Cotisation d\'un centre, relancée par le CNCGA seul ; un CGA reçoit 403 (Policy).',
            'network.dues.payments.confirm:paymentId' => 'Paiement de cotisation confirmé par le CNCGA seul ; un CGA reçoit 403 (Policy).',
            'network.dues.payments.reject:paymentId' => 'Paiement de cotisation rejeté par le CNCGA seul ; un CGA reçoit 403 (Policy).',
            'network.concerns.show:concernId' => 'Demande d\'un CGA lue par le CNCGA, qui voit celles de tous les centres (05-BACKEND-SCHEMA §12) ; un CGA reçoit 403.',
            'network.concerns.messages.store:concernId' => 'Demande d\'un CGA à laquelle répond le CNCGA seul ; un CGA reçoit 403.',
            'network.concerns.status:concernId' => 'Demande d\'un CGA dont le CNCGA seul change le statut ; un CGA reçoit 403.',
            'network.trainings.registrations.approve:registrationId' => $registrationId,
            'network.trainings.registrations.reject:registrationId' => $registrationId,
            'network.trainings.registrations.payment:registrationId' => $registrationId,
            'cga.trainings.registrations.approve:registrationId' => $registrationId,
            'cga.trainings.registrations.reject:registrationId' => $registrationId,
            'cga.trainings.registrations.payment:registrationId' => $registrationId,
            'cga.network-trainings.show:trainingId' => $networkTraining,
            'cga.network-trainings.registrations.index:trainingId' => $networkTraining,
            'cga.network-trainings.registrations.store:trainingId' => $networkTraining,
            'cga.network-trainings.candidates:trainingId' => $networkTraining,
            'me.announcements.read:announcementId' => 'Annonce reçue dans l\'espace actif : cherchée parmi celles qu\'il reçoit ; toute autre répond 404.',
            'me.trainings.register:trainingId' => 'Formation ouverte à la personne de l\'espace actif : cherchée parmi celles de son CGA et du CNCGA qui la ciblent ; toute autre répond 404.',
            'me.notifications.read:notificationId' => 'Notification de la cloche (PRD M13) : cherchée parmi celles de l\'utilisateur connecté dans son espace actif ; toute autre répond 404.',
            'cga.permanent-profile.resources.save:category' => 'Catégorie de moyens matériels de la fiche permanente (F-RPT-01) : la ligne est cherchée dans le CGA de l\'espace actif ; une valeur inconnue répond 404.',
        ];
    }

    /** Campagne de clôture du CGA B, créée une fois (une seule par CGA et par exercice). */
    private static function closingCampaignOf(Organization $other): ClosingCampaign
    {
        return Tenancy::bypass(static fn (): ?ClosingCampaign => ClosingCampaign::query()->where('organization_id', $other->id)->first(), 'Test de cloisonnement : campagne du CGA B.')
            ?? ClosingCampaign::factory()->create(['organization_id' => $other->id]);
    }
}
