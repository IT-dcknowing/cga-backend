<?php

namespace App\Domain\Exports\Exporters;

use App\Domain\Exports\Contracts\Exporter;
use App\Domain\Exports\Data\ExportCriteria;
use App\Domain\Exports\Data\ExportSheet;
use App\Domain\Exports\Support\ExportFormat;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Members\Models\Member;
use App\Support\Tenancy\TenantContext;
use Generator;
use Illuminate\Validation\Rule;

/**
 * Adhérents du centre (F-EXP-01, /cga/adherents) : portefeuille CGA par défaut, Cabinet ou tous avec
 * members.cabinet.view (RG-PTF-02) ; statuts et régimes choisis ; période sur la date d'adhésion. L'apporteur
 * (« Accessoire n ») n'est exporté qu'avec members.referrers.view, et seulement pour un dossier du portefeuille CGA
 * (RG-PTF-06).
 */
final class MembersExporter implements Exporter
{
    public function __construct(private readonly TenantContext $context) {}

    public function filterRules(): array
    {
        return [
            'portfolio' => ['nullable', 'string', Rule::in($this->context->allows('members.cabinet.view') ? ['cga', 'cabinet', 'all'] : ['cga'])],
            'statuses' => ['nullable', 'array'],
            'statuses.*' => ['string', Rule::enum(MemberStatus::class)],
            'regimes' => ['nullable', 'array'],
            'regimes.*' => ['string', Rule::enum(TaxRegime::class)],
        ];
    }

    public function sheets(ExportCriteria $criteria): iterable
    {
        $referrers = $this->context->allows('members.referrers.view');
        $headers = ['number', 'legacy_number', 'client_number', 'company_name', 'sigle', 'ncc', 'regime', 'portfolio', 'status', 'adhesion_date', 'sector', 'commune', 'tax_office', 'phone', 'email', 'fees_amount', 'fees_periodicity'];
        if ($referrers) {
            $headers[] = 'referrer';
        }

        yield new ExportSheet(
            (string) __('exports.sheets.members'),
            array_map(static fn (string $key): string => (string) __("exports.columns.members.{$key}"), $headers),
            $this->rows($criteria, $referrers),
        );
    }

    /**
     * @return Generator<int, list<string|int|null>>
     */
    private function rows(ExportCriteria $criteria, bool $referrers): Generator
    {
        $portfolio = $criteria->value('portfolio') ?? Portfolio::Cga->value;
        $query = Member::query()
            ->with(['sector', 'commune', 'taxOffice', ...($referrers ? ['referrer'] : [])])
            ->when($portfolio !== 'all', static fn ($members) => $members->where('portfolio', $portfolio))
            ->when($criteria->list('statuses') !== [], static fn ($members) => $members->whereIn('status', $criteria->list('statuses')))
            ->when($criteria->list('regimes') !== [], static fn ($members) => $members->whereIn('regime', $criteria->list('regimes')))
            ->when($criteria->from !== null, static fn ($members) => $members->whereBetween('adhesion_date', [$criteria->from?->toDateString(), $criteria->to?->toDateString()]))
            ->orderBy('company_name')
            ->orderBy('id');

        foreach ($query->lazy() as $member) {
            $row = [
                $member->membership_number,
                $member->legacy_number,
                $member->client_number,
                $member->company_name,
                $member->sigle,
                $member->ncc,
                $member->regime->value,
                $member->portfolio->label(),
                $member->status->label(),
                ExportFormat::date($member->adhesion_date),
                $member->sector?->name,
                $member->commune?->name,
                $member->taxOffice?->name,
                $member->phone,
                $member->email,
                $member->fees_amount,
                $member->fees_periodicity->label(),
            ];
            if ($referrers) {
                $row[] = $member->portfolio === Portfolio::Cga ? $member->referrer?->label : null;
            }

            yield $row;
        }
    }
}
