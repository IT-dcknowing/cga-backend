<?php

namespace App\Domain\Announcements\Http\Requests;

use App\Domain\Announcements\Http\Requests\Concerns\AuthorSpace;
use App\Domain\Announcements\Models\Announcement;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * GET /{network|cga}/announcements/{announcement} : une annonce de l'auteur, avec ses destinataires et ses lectures.
 */
class AuthorAnnouncementRequest extends FormRequest
{
    use AuthorSpace;

    public function authorize(): bool
    {
        $announcement = $this->route('announcement');
        if (! $announcement instanceof Announcement) {
            throw (new ModelNotFoundException)->setModel(Announcement::class);
        }

        return $this->authorSpace() && Gate::allows('view', $announcement);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
