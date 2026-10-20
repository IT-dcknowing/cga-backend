<?php

namespace Tests\Support;

use App\Domain\Documents\Models\File;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Referentials\Models\Commune;
use App\Domain\Referentials\Models\Holiday;
use App\Domain\Referentials\Models\NationalReference;
use App\Domain\Referentials\Models\RegionalDirectorate;
use App\Domain\Referentials\Models\Sector;
use App\Domain\Referentials\Models\TaxOffice;
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

        return [
            'auth.invitations.show:invitationToken' => $invitationToken,
            'auth.invitations.accept:invitationToken' => $invitationToken,
        ];
    }
}
