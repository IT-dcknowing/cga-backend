<?php

namespace App\Domain\Announcements\Http\Requests;

use App\Domain\Announcements\Enums\AnnouncementAudience;
use App\Domain\Announcements\Http\Requests\Concerns\AuthorSpace;
use App\Domain\Announcements\Models\Announcement;
use App\Support\Http\ListRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Listes des annonces, les plus récentes d'abord : GET /{network|cga}/announcements (celles de l'auteur) et
 * GET /me/announcements (celles reçues dans l'espace actif, filtre « non lues ») ; filtre par cible pour l'auteur.
 */
class ListAnnouncementsRequest extends ListRequest
{
    use AuthorSpace;

    public function authorize(): bool
    {
        return $this->routeIs('me.*')
            ? Gate::allows('receive', Announcement::class)
            : $this->authorSpace() && Gate::allows('publish', Announcement::class);
    }

    protected function filters(): array
    {
        return $this->routeIs('me.*') ? ['unread' => ['boolean']] : ['audience' => [Rule::enum(AnnouncementAudience::class)]];
    }

    public function sorts(): array
    {
        return ['published_at'];
    }
}
