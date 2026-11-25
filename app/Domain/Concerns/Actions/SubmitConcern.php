<?php

namespace App\Domain\Concerns\Actions;

use App\Domain\Concerns\Enums\ConcernCategory;
use App\Domain\Concerns\Enums\ConcernLevel;
use App\Domain\Concerns\Enums\ConcernSide;
use App\Domain\Concerns\Enums\ConcernStatus;
use App\Domain\Concerns\Models\Concern;
use App\Domain\Concerns\Support\ConcernNotifier;
use App\Domain\Concerns\Support\ConcernThread;
use App\Domain\Users\Models\Membership;
use App\Support\Config\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Soumettre une préoccupation (F-PRE-01) : l'adhérent à son CGA, ou le gérant et l'expert-comptable au
 * CNCGA — catégorie, objet, message, pièces jointes. Ouverte, avec le délai de réponse visé de son niveau
 * (RG-PRE-01, concerns.target_days : indicatif, jamais bloquant) ; qui la traite est notifié.
 */
class SubmitConcern
{
    public function __construct(
        private readonly ConcernThread $thread,
        private readonly ConcernNotifier $notifier,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     */
    public function execute(ConcernLevel $level, ConcernCategory $category, string $subject, string $body, array $files, Membership $actor): Concern
    {
        return DB::transaction(function () use ($level, $category, $subject, $body, $files, $actor): Concern {
            $now = CarbonImmutable::now();
            $concern = $this->open($level, $category, $subject, $actor, $now);
            $this->thread->post($concern, $actor->user_id, ConcernSide::Requester, $body, $files, at: $now);
            $this->notifier->submitted($concern);

            return $concern;
        });
    }

    /**
     * Demande ouverte dans le CGA de l'espace actif : celui de l'adhérent, ou le centre qui écrit au CNCGA.
     */
    public function open(ConcernLevel $level, ConcernCategory $category, string $subject, Membership $actor, CarbonImmutable $now, ?Concern $transferredFrom = null): Concern
    {
        /** @var array<string, int> $targets */
        $targets = (array) $this->settings->get('concerns.target_days', $actor->organization_id);

        $concern = new Concern;
        $concern->organization_id = $actor->organization_id;
        $concern->level = $level;
        $concern->member_id = $level === ConcernLevel::MemberToCga ? $actor->member_id : null;
        $concern->author_user_id = $actor->user_id;
        $concern->category = $category;
        $concern->subject = $subject;
        $concern->status = ConcernStatus::Open;
        $concern->transferred_from_concern_id = $transferredFrom?->id;
        $concern->target_response_days = (int) $targets[$level->value];
        $concern->last_activity_at = $now;
        $concern->awaiting_reply_since = $now;
        $concern->save();

        return $concern;
    }
}
