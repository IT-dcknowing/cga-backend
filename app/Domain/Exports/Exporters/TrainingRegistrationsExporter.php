<?php

namespace App\Domain\Exports\Exporters;

use App\Domain\Exports\Contracts\Exporter;
use App\Domain\Exports\Data\ExportCriteria;
use App\Domain\Exports\Data\ExportSheet;
use App\Domain\Exports\Support\ExportFormat;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Trainings\Enums\RegistrationStatus;
use App\Domain\Trainings\Models\Training;
use App\Domain\Trainings\Models\TrainingRegistration;
use App\Domain\Trainings\Support\TrainingAccess;
use App\Support\Tenancy\TenantContext;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Inscrits aux formations de l'organisateur (F-EXP-01, onglet « Inscrits » d'une formation du CNCGA ou d'un CGA) :
 * période sur la date de la session, statuts choisis ; mêmes colonnes que la liste. L'entreprise d'un adhérent
 * n'est exportée que pour un CGA organisateur, jamais pour le CNCGA (RG-SEC-01, décision 0013).
 */
final class TrainingRegistrationsExporter implements Exporter
{
    public function __construct(private readonly TenantContext $context) {}

    public function filterRules(): array
    {
        return [
            'statuses' => ['nullable', 'array'],
            'statuses.*' => ['string', Rule::enum(RegistrationStatus::class)],
        ];
    }

    public function sheets(ExportCriteria $criteria): iterable
    {
        $company = $this->context->organizationType() === OrganizationType::Cga;
        $headers = ['training', 'starts_at', 'person', 'kind', 'organization', ...($company ? ['company'] : []), 'status', 'payment_status', 'paid_on', 'receipt_number', 'attendance'];

        yield new ExportSheet(
            (string) __('exports.sheets.training_registrations'),
            array_map(static fn (string $key): string => (string) __("exports.columns.training_registrations.{$key}"), $headers),
            $this->rows($criteria, $company),
        );
    }

    /**
     * @return Generator<int, list<string|int|null>>
     */
    private function rows(ExportCriteria $criteria, bool $company): Generator
    {
        $trainings = Training::query()
            ->when($criteria->from !== null, static fn (Builder $query) => $query->whereBetween('starts_at', [$criteria->from?->startOfDay(), $criteria->to?->endOfDay()]))
            ->pluck('id')
            ->all();
        $registrations = TrainingAccess::acrossCentres(static fn () => TrainingRegistration::query()
            ->with(['training', 'user', 'membership.organization', 'member'])
            ->whereIn('training_id', $trainings)
            ->when($criteria->list('statuses') !== [], static fn (Builder $query) => $query->whereIn('status', $criteria->list('statuses')))
            ->get()
            ->sortBy(static fn (TrainingRegistration $registration): string => $registration->training->starts_at->toIso8601String().$registration->training_id.$registration->created_at->toIso8601String())
            ->values());

        foreach ($registrations as $registration) {
            yield [
                $registration->training->title,
                ExportFormat::dateTime($registration->training->starts_at),
                $registration->user->fullName(),
                (string) __($registration->member_id === null ? 'exports.values.agent' : 'exports.values.member'),
                $registration->membership->organization->name,
                ...($company ? [$registration->member?->company_name] : []),
                $registration->status->label(),
                $registration->payment_status->label(),
                ExportFormat::date($registration->paid_on),
                $registration->receipt_number,
                $registration->attendance?->label(),
            ];
        }
    }
}
