<?php

namespace App\Domain\Announcements\Http\Requests;

use App\Domain\Announcements\Enums\AnnouncementAudience;
use App\Domain\Announcements\Http\Requests\Concerns\AuthorSpace;
use App\Domain\Announcements\Models\Announcement;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Support\Config\SettingsService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * POST /network/announcements (F-COM-01 : tous les CGA, certains CGA choisis, tous les adhérents du réseau) et
 * POST /cga/announcements (F-COM-02 : ses adhérents, tous ou filtrés par portefeuille, régime, secteur), en
 * multipart : titre, texte, pièce jointe facultative, e-mail en option. Le portefeuille Cabinet n'est visé
 * qu'avec members.cabinet.view.
 */
class PublishAnnouncementRequest extends FormRequest
{
    use AuthorSpace;

    public function authorize(): bool
    {
        return $this->authorSpace() && Gate::allows('publish', Announcement::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(SettingsService $settings, TenantContext $context): array
    {
        $max = (int) $settings->get('pagination.max');
        $rules = [
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:'.(int) config('cga.announcements.body_max_length')],
            'attachment' => ['nullable', 'file', 'mimetypes:'.implode(',', (array) $settings->get('files.allowed_types')), 'max:'.((int) $settings->get('files.max_upload_mb') * 1024)],
            'send_email' => ['sometimes', 'boolean'],
        ];

        if ($this->routeIs('network.*')) {
            return [
                ...$rules,
                'audience' => ['required', Rule::in(array_map(static fn (AnnouncementAudience $audience): string => $audience->value, AnnouncementAudience::network()))],
                'organization_ids' => ['nullable', 'required_if:audience,'.AnnouncementAudience::SelectedCgas->value, 'array', 'max:'.$max],
                'organization_ids.*' => ['string', 'ulid', 'distinct', Rule::exists('organizations', 'id')->where('type', OrganizationType::Cga->value)],
            ];
        }

        $portfolios = $context->allows('members.cabinet.view') ? [Portfolio::Cga->value, Portfolio::Cabinet->value, 'all'] : [Portfolio::Cga->value];

        return [
            ...$rules,
            'filter' => ['sometimes', 'array:portfolio,regimes,sector_ids'],
            'filter.portfolio' => ['nullable', Rule::in($portfolios)],
            'filter.regimes' => ['nullable', 'array'],
            'filter.regimes.*' => [Rule::enum(TaxRegime::class)],
            'filter.sector_ids' => ['nullable', 'array', 'max:'.$max],
            'filter.sector_ids.*' => ['string', 'size:26', 'distinct', Rule::exists('sectors', 'id')],
        ];
    }

    public function audience(): AnnouncementAudience
    {
        return $this->routeIs('network.*') ? AnnouncementAudience::from((string) $this->validated('audience')) : AnnouncementAudience::OwnMembers;
    }

    /**
     * @return list<string>
     */
    public function targets(): array
    {
        return array_values(array_map('strval', (array) ($this->validated('organization_ids') ?? [])));
    }

    /**
     * @return array{portfolio: string, regimes: list<string>, sector_ids: list<string>}|null
     */
    public function filter(): ?array
    {
        if ($this->routeIs('network.*')) {
            return null;
        }
        $filter = (array) ($this->validated('filter') ?? []);

        return [
            'portfolio' => is_string($filter['portfolio'] ?? null) ? $filter['portfolio'] : Portfolio::Cga->value,
            'regimes' => array_values(array_map('strval', (array) ($filter['regimes'] ?? []))),
            'sector_ids' => array_values(array_map('strval', (array) ($filter['sector_ids'] ?? []))),
        ];
    }

    public function attachment(): ?UploadedFile
    {
        $file = $this->validated('attachment');

        return $file instanceof UploadedFile ? $file : null;
    }

    public function sendEmail(): bool
    {
        return (bool) $this->validated('send_email', false);
    }

    public function announcementTitle(): string
    {
        return (string) $this->validated('title');
    }

    public function body(): string
    {
        return (string) $this->validated('body');
    }
}
