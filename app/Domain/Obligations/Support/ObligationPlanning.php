<?php

namespace App\Domain\Obligations\Support;

use App\Domain\Obligations\Enums\InstanceKind;
use App\Domain\Obligations\Enums\InstanceStatus;
use App\Domain\Obligations\Models\ObligationInstance;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Planning des échéances du portefeuille (F-OBL-06) : échéances regroupées par date, obligation et
 * nature (« 15/11 — CNPS — 34 adhérents »), avec leur statut agrégé (faits / à faire) ; indicateur
 * « Échéances sous n jours » (n paramétrable, 15 par défaut). Les échéances annulées n'y figurent pas.
 */
final class ObligationPlanning
{
    /**
     * Statuts d'une échéance traitée : déclaration faite, paiement soldé, diligence établie.
     *
     * @return list<InstanceStatus>
     */
    public static function finished(): array
    {
        return [InstanceStatus::Done, InstanceStatus::Paid, InstanceStatus::Established];
    }

    /**
     * @param  'open'|'done'|null  $progress
     * @return Collection<int, array{due_date: string, obligation_type_id: string|null, label: string, category: string, kind: string, platform: string|null, members: int, done: int, open: int}>
     */
    public function groups(CarbonImmutable $from, CarbonImmutable $to, ?string $typeId, ?string $progress): Collection
    {
        $open = self::values(ObligationSummary::open());
        $finished = self::values(self::finished());

        return $this->lines($from, $to, $typeId, $progress)
            ->selectRaw('due_date, obligation_type_id, label, category, kind, platform, COUNT(DISTINCT member_id) AS members')
            // Un paramètre lié par statut : 3 statuts traités, 5 à traiter (finished(), ObligationSummary::open()).
            ->selectRaw('SUM(CASE WHEN status IN (?, ?, ?) THEN 1 ELSE 0 END) AS done_count', $finished)
            ->selectRaw('SUM(CASE WHEN status IN (?, ?, ?, ?, ?) THEN 1 ELSE 0 END) AS open_count', $open)
            ->groupBy('due_date', 'obligation_type_id', 'label', 'category', 'kind', 'platform')
            ->orderBy('due_date')
            ->orderBy('label')
            ->orderBy('kind')
            ->toBase()
            ->get()
            ->map(static fn (object $row): array => [
                'due_date' => CarbonImmutable::parse((string) $row->due_date)->toDateString(),
                'obligation_type_id' => $row->obligation_type_id === null ? null : (string) $row->obligation_type_id,
                'label' => (string) $row->label,
                'category' => (string) $row->category,
                'kind' => (string) $row->kind,
                'platform' => $row->platform === null ? null : (string) $row->platform,
                'members' => (int) $row->members,
                'done' => (int) $row->done_count,
                'open' => (int) $row->open_count,
            ])
            ->values();
    }

    /**
     * Échéances encore à traiter dans les `days` prochains jours, et dossiers concernés.
     *
     * @return array{days: int, count: int, members: int}
     */
    public function upcoming(CarbonImmutable $today, int $days): array
    {
        $query = ObligationInstance::query()
            ->whereIn('status', ObligationSummary::open())
            ->whereBetween('due_date', [$today->toDateString(), $today->addDays($days)->toDateString()]);

        return [
            'days' => $days,
            'count' => (clone $query)->count(),
            'members' => (clone $query)->distinct()->count('member_id'),
        ];
    }

    /**
     * Échéances d'une ligne du planning (panneau des adhérents concernés).
     *
     * @return Builder<ObligationInstance>
     */
    public function group(CarbonImmutable $dueDate, InstanceKind $kind, ?string $typeId, string $label): Builder
    {
        return ObligationInstance::query()
            ->where('status', '<>', InstanceStatus::Cancelled)
            ->whereDate('due_date', $dueDate->toDateString())
            ->where('kind', $kind)
            ->where('label', $label)
            ->when($typeId === null, static fn (Builder $query) => $query->whereNull('obligation_type_id'))
            ->when($typeId !== null, static fn (Builder $query) => $query->where('obligation_type_id', $typeId));
    }

    /**
     * @param  'open'|'done'|null  $progress
     * @return Builder<ObligationInstance>
     */
    private function lines(CarbonImmutable $from, CarbonImmutable $to, ?string $typeId, ?string $progress): Builder
    {
        return ObligationInstance::query()
            ->where('status', '<>', InstanceStatus::Cancelled)
            ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
            ->when($typeId !== null, static fn (Builder $query) => $query->where('obligation_type_id', $typeId))
            ->when($progress === 'open', static fn (Builder $query) => $query->whereIn('status', ObligationSummary::open()))
            ->when($progress === 'done', static fn (Builder $query) => $query->whereIn('status', self::finished()));
    }

    /**
     * @param  list<InstanceStatus>  $statuses
     * @return list<string>
     */
    private static function values(array $statuses): array
    {
        return array_map(static fn (InstanceStatus $status): string => $status->value, $statuses);
    }
}
