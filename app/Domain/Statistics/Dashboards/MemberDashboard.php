<?php

namespace App\Domain\Statistics\Dashboards;

use App\Domain\Announcements\Models\Announcement;
use App\Domain\Announcements\Support\AnnouncementFeed;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Obligations\Enums\ObligationCategory;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Domain\Obligations\Support\ObligationSummary;
use App\Domain\Payments\Enums\ChargeKind;
use App\Domain\Payments\Enums\ChargeStatus;
use App\Domain\Payments\Models\MemberCharge;
use App\Domain\Users\Enums\MembershipStatus;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tableau de bord de l'adhérent (F-DSH-03, 03-APPFLOW §5.2), sans période : prochaine échéance, honoraires (à jour
 * ou impayés, montant), impôts dus, CNPS + CMU dus, échéances du mois, dernier message reçu de son CGA ou du réseau,
 * documents récents. Tout est lu dans son seul dossier ; chaque bloc n'est rendu qu'avec le droit de l'écran qu'il
 * ouvre (un accès en consultation seule après une radiation ne garde que ses documents). Les listes sont limitées à
 * cga.statistics.member_dashboard_items lignes.
 */
final class MemberDashboard
{
    public function __construct(
        private readonly AnnouncementFeed $feed,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(CarbonImmutable $today): array
    {
        $memberId = (string) $this->context->memberId();
        $limit = (int) config('cga.statistics.member_dashboard_items');
        $dashboard = [];
        // Après une radiation validée, l'accès en consultation seule ne garde que les documents (03-APPFLOW §5.2).
        $active = $this->context->membership()->status !== MembershipStatus::ReadOnly;

        if ($active && $this->context->allows('member.obligations.view')) {
            $instances = ObligationInstance::query()->where('member_id', $memberId)->whereIn('status', ObligationSummary::open())->orderBy('due_date')->orderBy('label')->get();
            $summary = ObligationSummary::byCategory($instances, $today);
            $line = static fn (ObligationInstance $instance): array => [
                'id' => $instance->id,
                'label' => $instance->label,
                'kind' => $instance->kind->value,
                'category' => $instance->category->value,
                'due_date' => $instance->due_date->toDateString(),
                'amount' => $instance->balance(),
            ];
            $next = $instances->first(static fn (ObligationInstance $instance): bool => $instance->due_date->greaterThanOrEqualTo($today));
            $month = $instances->filter(static fn (ObligationInstance $instance): bool => $instance->due_date->betweenIncluded($today->startOfMonth(), $today->endOfMonth()))->values();
            $dashboard['next_due'] = $next === null ? null : $line($next);
            $dashboard['taxes_due'] = $summary[ObligationCategory::Tax->value]['total_due'];
            $dashboard['social_due'] = $summary[ObligationCategory::Cnps->value]['total_due'] + $summary[ObligationCategory::Cmu->value]['total_due'];
            $dashboard['month_dues'] = ['count' => $month->count(), 'items' => $month->take($limit)->map($line)->values()->all()];
        }

        if ($active && $this->context->allows('member.payments.declare')) {
            $fees = MemberCharge::query()->where('member_id', $memberId)->where('kind', ChargeKind::Fees);
            $overdue = (clone $fees)->where('status', ChargeStatus::Overdue);
            $next = (clone $fees)->whereIn('status', [ChargeStatus::Due, ChargeStatus::Partial])->where('due_date', '>=', $today->toDateString())->orderBy('due_date')->first();
            $periods = (clone $overdue)->count();
            $dashboard['fees'] = [
                'status' => $periods > 0 ? 'overdue' : 'up_to_date',
                'overdue_amount' => (int) (clone $overdue)->selectRaw('coalesce(sum(amount - amount_paid), 0) as unpaid')->value('unpaid'),
                'overdue_periods' => $periods,
                'next' => $next === null ? null : ['due_date' => $next->due_date->toDateString(), 'amount' => $next->amount - $next->amount_paid],
            ];
        }

        if ($active) {
            $dashboard['last_message'] = $this->lastMessage();
        }

        if ($this->context->allows('member.documents.view')) {
            $dashboard['recent_documents'] = GeneratedDocument::query()
                ->where('member_id', $memberId)
                ->where('visible_to_member', true)
                ->where('status', DocumentStatus::Valid)
                ->orderByDesc('issued_at')
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get()
                ->map(static fn (GeneratedDocument $document): array => [
                    'id' => $document->id,
                    'type' => $document->type->value,
                    'title' => $document->title,
                    'number' => $document->number,
                    'issued_at' => $document->issued_at?->toDateString(),
                ])
                ->values()
                ->all();
        }

        return $dashboard;
    }

    /**
     * Dernière annonce reçue dans l'espace actif (« Message de mon CGA ») et sa lecture.
     *
     * @return array{id: string, title: string, from: string, published_at: string, read: bool}|null
     */
    private function lastMessage(): ?array
    {
        $userId = $this->context->userId();
        $announcement = Tenancy::bypass(
            fn (): ?Announcement => $this->feed->received($this->context->membership())
                ->with('organization')
                ->withExists(['reads as read' => static fn (Builder $reads) => $reads->where('user_id', $userId)])
                ->orderByDesc('published_at')
                ->first(),
            'Tableau de bord de l\'adhérent : dernière annonce reçue.',
        );

        return $announcement === null ? null : [
            'id' => $announcement->id,
            'title' => $announcement->title,
            'from' => $announcement->organization->name,
            'published_at' => $announcement->published_at->toIso8601String(),
            'read' => (bool) $announcement->getAttribute('read'),
        ];
    }
}
