<?php

namespace App\Domain\Announcements\Http\Requests;

use App\Domain\Announcements\Models\Announcement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * POST /me/announcements/{announcementId}/read : une annonce reçue marquée comme lue.
 */
class ReadAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('receive', Announcement::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
