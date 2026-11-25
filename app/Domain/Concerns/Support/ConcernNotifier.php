<?php

namespace App\Domain\Concerns\Support;

use App\Domain\Concerns\Enums\ConcernLevel;
use App\Domain\Concerns\Models\Concern;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Models\Member;
use App\Domain\Notifications\Support\Recipients;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Permissions\PermissionResolver;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Collection;

/**
 * Notifications des préoccupations (PRD M13) :
 * - « Nouvelle préoccupation » : à qui la traite — agents du CGA qui répondent aux adhérents (un dossier
 *   Cabinet : ceux qui voient ce portefeuille), ou agents du CNCGA qui traitent les demandes des CGA ;
 * - « Réponse / changement de statut » : au demandeur — accès en ligne du dossier, ou agents du CGA qui
 *   écrivent au CNCGA ;
 * - activité du demandeur (nouveau message, demande rouverte) : à qui la traite (décision 0012).
 * Aucune remontée automatique au CNCGA d'une demande d'adhérent (RG-PRE-02).
 */
final class ConcernNotifier
{
    public function __construct(
        private readonly Recipients $recipients,
        private readonly PermissionResolver $permissions,
    ) {}

    public function submitted(Concern $concern): void
    {
        $this->recipients->notify($this->handlers($concern), 'concern_new', ['from' => $this->from($concern), 'subject' => $concern->subject], $this->handlerPath($concern));
    }

    /**
     * @param  'reply'|'reopened'  $event
     */
    public function followUp(Concern $concern, string $event): void
    {
        $this->recipients->notify($this->handlers($concern), 'concern_followup', [
            'from' => $this->from($concern),
            'subject' => $concern->subject,
            'event' => (string) __("concerns.followups.{$event}"),
        ], $this->handlerPath($concern));
    }

    /**
     * @param  'reply'|'status'  $event
     */
    public function updated(Concern $concern, string $event): void
    {
        $this->recipients->notify($this->requesters($concern), 'concern_updated', [
            'subject' => $concern->subject,
            'event' => (string) __("concerns.events.{$event}", ['status' => $concern->status->label()]),
        ], $concern->level === ConcernLevel::MemberToCga ? "/adherent/messages/{$concern->id}" : "/cga/preoccupations/{$concern->id}");
    }

    /**
     * Agents qui traitent la demande.
     *
     * @return Collection<int, Membership>
     */
    public function handlers(Concern $concern): Collection
    {
        if ($concern->level === ConcernLevel::CgaToCncga) {
            return $this->recipients->network('network_concerns.handle');
        }

        $agents = $this->recipients->agents($concern->organization_id, 'concerns.reply');
        if ($this->member($concern)?->portfolio !== Portfolio::Cabinet) {
            return $agents;
        }

        return $agents->filter(fn (Membership $agent): bool => $this->permissions->allows($agent, 'members.cabinet.view'))->values();
    }

    /**
     * @return Collection<int, Membership>
     */
    private function requesters(Concern $concern): Collection
    {
        return $concern->level === ConcernLevel::MemberToCga && $concern->member_id !== null
            ? $this->recipients->memberAccesses($concern->member_id)
            : $this->recipients->agents($concern->organization_id, 'concerns.network.write');
    }

    private function handlerPath(Concern $concern): string
    {
        return $concern->level === ConcernLevel::MemberToCga ? "/cga/preoccupations/{$concern->id}" : "/cncga/preoccupations/{$concern->id}";
    }

    /** Demandeur nommé dans la notification : le dossier pour le CGA, le centre pour le CNCGA. */
    private function from(Concern $concern): string
    {
        return $concern->level === ConcernLevel::MemberToCga
            ? (string) $this->member($concern)?->company_name
            : (string) Organization::query()->whereKey($concern->organization_id)->value('name');
    }

    private function member(Concern $concern): ?Member
    {
        if ($concern->member_id === null) {
            return null;
        }

        return Tenancy::bypass(static fn (): ?Member => Member::query()->find($concern->member_id), 'Notifications : dossier d\'une préoccupation.');
    }
}
