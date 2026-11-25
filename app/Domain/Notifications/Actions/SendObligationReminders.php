<?php

namespace App\Domain\Notifications\Actions;

use App\Domain\Documents\Support\DocumentFormat;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Notifications\Support\Recipients;
use App\Domain\Obligations\Enums\InstanceKind;
use App\Domain\Obligations\Enums\InstanceStatus;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Support\Config\SettingsService;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * `reminders:obligations` chaque jour (02-TRD §11, PRD M13) : échéances fiscales et sociales à faire ou à
 * payer — rappel à l'adhérent à J-7 puis J-1 (obligations.reminder_days) ; échéance dépassée non
 * réglée, à l'adhérent et aux agents qui suivent les obligations. Chaque rappel est daté sur l'échéance :
 * relancer la tâche ne renvoie rien.
 */
class SendObligationReminders
{
    private const OPEN = [InstanceStatus::Todo, InstanceStatus::ToPay, InstanceStatus::PartiallyPaid];

    public function __construct(
        private readonly Recipients $recipients,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @return array{upcoming: int, overdue: int}
     */
    public function execute(CarbonImmutable $today): array
    {
        return Tenancy::bypass(function () use ($today): array {
            /** @var array{first: int, last: int} $days */
            $days = (array) $this->settings->get('obligations.reminder_days');
            $totals = ['upcoming' => 0, 'overdue' => 0];
            foreach (['reminder_j7_sent_at' => $days['first'], 'reminder_j1_sent_at' => $days['last']] as $column => $before) {
                $this->open()->whereDate('due_date', $today->addDays($before))->whereNull($column)->each(function (ObligationInstance $instance) use ($column, &$totals): void {
                    $this->recipients->notify($this->recipients->memberAccesses($instance->member_id), 'obligation_due', $this->values($instance), '/adherent/obligations');
                    $instance->setAttribute($column, now());
                    $instance->save();
                    $totals['upcoming']++;
                });
            }
            $this->open()->whereDate('due_date', '<', $today)->whereNull('overdue_notified_at')->each(function (ObligationInstance $instance) use (&$totals): void {
                $this->recipients->notify($this->recipients->memberAccesses($instance->member_id), 'obligation_overdue', $this->values($instance), '/adherent/obligations');
                $this->recipients->notify($this->recipients->agents($instance->organization_id, 'obligations.manage'), 'obligation_overdue', $this->values($instance), "/cga/adherents/{$instance->member_id}");
                $instance->overdue_notified_at = now();
                $instance->save();
                $totals['overdue']++;
            });

            return $totals;
        }, 'Tâche reminders:obligations : rappels d\'échéances de tous les CGA (PRD M13).');
    }

    /**
     * @return Builder<ObligationInstance>
     */
    private function open(): Builder
    {
        return ObligationInstance::query()
            ->with('member')
            ->whereIn('kind', [InstanceKind::Declaration, InstanceKind::Payment])
            ->whereIn('status', self::OPEN)
            ->whereHas('member', static fn (Builder $member) => $member->whereIn('status', [MemberStatus::Active, MemberStatus::RadiationPending]))
            ->orderBy('id');
    }

    /**
     * @return array<string, string>
     */
    private function values(ObligationInstance $instance): array
    {
        return [
            'label' => $instance->label,
            'period' => $instance->period_label,
            'date' => DocumentFormat::date(CarbonImmutable::parse($instance->due_date)),
            'member' => $instance->member->company_name,
        ];
    }
}
