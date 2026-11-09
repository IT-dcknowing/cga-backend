<?php

namespace App\Domain\Concerns\Http\Requests;

use App\Domain\Concerns\Enums\ConcernCategory;
use App\Domain\Concerns\Http\Requests\Concerns\ConcernAttachments;
use App\Domain\Concerns\Models\Concern;
use App\Support\Config\SettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * POST /member/concerns (« Nouvelle demande » de l'adhérent à son CGA) et POST /cga/concerns (« Écrire au
 * CNCGA », gérant et expert-comptable) : catégorie, objet, message, pièces jointes facultatives (F-PRE-01),
 * en multipart. Le niveau, le centre, le dossier et l'auteur viennent de l'espace actif, jamais de la requête.
 */
class StoreConcernRequest extends FormRequest
{
    use ConcernAttachments;

    public function authorize(): bool
    {
        return Gate::allows($this->routeIs('member.*') ? 'writeOwn' : 'writeToNetwork', Concern::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(SettingsService $settings): array
    {
        return [
            'category' => ['required', Rule::enum(ConcernCategory::class)],
            'subject' => ['required', 'string', 'max:'.(int) config('cga.concerns.subject_max_length')],
            'message' => ['required', 'string', $this->messageRule()],
            ...$this->attachmentRules($settings),
        ];
    }

    public function category(): ConcernCategory
    {
        return ConcernCategory::from((string) $this->validated('category'));
    }

    public function subject(): string
    {
        return (string) $this->validated('subject');
    }

    public function body(): string
    {
        return (string) $this->validated('message');
    }
}
