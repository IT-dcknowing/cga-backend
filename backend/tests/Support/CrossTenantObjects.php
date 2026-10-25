<?php

namespace Tests\Support;

use App\Domain\Documents\Models\DocumentTemplate;
use App\Domain\Documents\Models\File;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Imports\Enums\ImportStatus;
use App\Domain\Imports\Enums\ImportType;
use App\Domain\Imports\Models\ImportBatch;
use App\Domain\Members\Models\BusinessReferrer;
use App\Domain\Members\Models\Member;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Payments\Enums\CertificationMode;
use App\Domain\Payments\Enums\InvoiceStatus;
use App\Domain\Payments\Enums\InvoiceType;
use App\Domain\Payments\Models\Invoice;
use App\Domain\Payments\Models\MemberPayment;
use App\Domain\Referentials\Models\Commune;
use App\Domain\Referentials\Models\Holiday;
use App\Domain\Referentials\Models\NationalReference;
use App\Domain\Referentials\Models\RegionalDirectorate;
use App\Domain\Referentials\Models\Sector;
use App\Domain\Referentials\Models\TaxOffice;
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
            'auth.invitations.show:invitationToken' => $invitationToken,
            'auth.invitations.accept:invitationToken' => $invitationToken,
            'public.verify:verificationCode' => 'Code aléatoire du QR d\'un document (page publique, F-DOC-04) : il désigne le document sans identifiant ; un code inconnu répond 404.',
            'cga.members.pieces.store:pieceType' => 'Type de pièce du dossier (F-PJ-01) : il ne désigne aucun objet, le dossier est contrôlé par {member} ; une valeur inconnue répond 404.',
            'cga.profile.images.store:kind' => 'Type d\'image (logo, signature, cachet) de la fiche du CGA de l\'espace actif : la route ne désigne aucun objet, une valeur inconnue répond 404.',
        ];
    }
}
