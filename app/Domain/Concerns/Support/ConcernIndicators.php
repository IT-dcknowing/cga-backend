<?php

namespace App\Domain\Concerns\Support;

use App\Domain\Concerns\Enums\ConcernCategory;
use App\Domain\Concerns\Enums\ConcernStatus;
use App\Domain\Concerns\Models\Concern;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tableau des délais des préoccupations (F-PRE-04) : des chiffres seulement, jamais le contenu d'une
 * demande (RG-PRE-03) — nombre, répartition par statut et par catégorie, taux de traitement (traitées ou
 * rejetées), délai moyen de première réponse et de clôture (heures), part des réponses dans le délai visé
 * de la demande (RG-PRE-01).
 */
final class ConcernIndicators
{
    private const ROUNDING = 1;

    private const RATE_ROUNDING = 3;

    private const SECONDS_PER_HOUR = 3600;

    /**
     * @param  Builder<Concern>  $concerns
     * @return array<string, mixed>
     */
    public static function summary(Builder $concerns): array
    {
        $row = self::aggregate($concerns)->toBase()->first();

        return [
            ...self::metrics($row),
            'by_category' => self::byCategory($concerns),
        ];
    }

    /**
     * Mêmes chiffres par CGA (indicateurs du CNCGA, onglet « Préoccupations » de la fiche d'un CGA).
     *
     * @param  Builder<Concern>  $concerns
     * @return array<string, array<string, mixed>> organisation => chiffres
     */
    public static function byOrganization(Builder $concerns): array
    {
        $rows = self::aggregate($concerns)->addSelect('organization_id')->groupBy('organization_id')->toBase()->get();
        $categories = (clone $concerns)->toBase()
            ->select(['organization_id', 'category'])
            ->selectRaw('count(*) as total')
            ->groupBy(['organization_id', 'category'])
            ->get()
            ->groupBy('organization_id');

        $result = [];
        foreach ($rows as $row) {
            $organizationId = (string) $row->organization_id;
            $result[$organizationId] = [
                ...self::metrics($row),
                'by_category' => self::categories($categories->get($organizationId, collect())->pluck('total', 'category')->all()),
            ];
        }

        return $result;
    }

    /**
     * @param  Builder<Concern>  $concerns
     * @return Builder<Concern>
     */
    private static function aggregate(Builder $concerns): Builder
    {
        $closed = [ConcernStatus::Resolved->value, ConcernStatus::Rejected->value];

        return (clone $concerns)->select([])
            ->selectRaw('count(*) as total')
            ->selectRaw('count(*) filter (where status = ?) as open', [ConcernStatus::Open->value])
            ->selectRaw('count(*) filter (where status = ?) as in_review', [ConcernStatus::InReview->value])
            ->selectRaw('count(*) filter (where status = ?) as resolved', [ConcernStatus::Resolved->value])
            ->selectRaw('count(*) filter (where status = ?) as rejected', [ConcernStatus::Rejected->value])
            ->selectRaw('count(first_response_at) as responded')
            ->selectRaw('count(*) filter (where first_response_at <= created_at + make_interval(days => target_response_days)) as on_target')
            ->selectRaw('avg(extract(epoch from (first_response_at - created_at))) as response_seconds')
            ->selectRaw('avg(extract(epoch from (resolved_at - created_at))) filter (where status in (?, ?)) as resolution_seconds', $closed);
    }

    /**
     * @return array<string, mixed>
     */
    private static function metrics(?object $row): array
    {
        $total = (int) ($row->total ?? 0);
        $closed = (int) ($row->resolved ?? 0) + (int) ($row->rejected ?? 0);
        $responded = (int) ($row->responded ?? 0);

        return [
            'count' => $total,
            'by_status' => [
                ConcernStatus::Open->value => (int) ($row->open ?? 0),
                ConcernStatus::InReview->value => (int) ($row->in_review ?? 0),
                ConcernStatus::Resolved->value => (int) ($row->resolved ?? 0),
                ConcernStatus::Rejected->value => (int) ($row->rejected ?? 0),
            ],
            'treatment_rate' => $total > 0 ? round($closed / $total, self::RATE_ROUNDING) : null,
            'responded' => $responded,
            'on_target_rate' => $responded > 0 ? round((int) $row->on_target / $responded, self::RATE_ROUNDING) : null,
            'average_response_hours' => self::hours($row->response_seconds ?? null),
            'average_resolution_hours' => self::hours($row->resolution_seconds ?? null),
        ];
    }

    /**
     * @param  Builder<Concern>  $concerns
     * @return array<string, int>
     */
    private static function byCategory(Builder $concerns): array
    {
        return self::categories((clone $concerns)->toBase()->select('category')->selectRaw('count(*) as total')->groupBy('category')->pluck('total', 'category')->all());
    }

    /**
     * Toutes les catégories, à zéro quand aucune demande.
     *
     * @param  array<string, mixed>  $counts
     * @return array<string, int>
     */
    private static function categories(array $counts): array
    {
        $result = [];
        foreach (ConcernCategory::cases() as $category) {
            $result[$category->value] = (int) ($counts[$category->value] ?? 0);
        }

        return $result;
    }

    private static function hours(mixed $seconds): ?float
    {
        return $seconds === null ? null : round((float) $seconds / self::SECONDS_PER_HOUR, self::ROUNDING);
    }
}
