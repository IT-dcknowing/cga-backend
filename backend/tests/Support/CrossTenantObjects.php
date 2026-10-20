<?php

namespace Tests\Support;

use App\Domain\Documents\Models\File;
use App\Domain\Organizations\Models\Organization;
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
