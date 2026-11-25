<?php

namespace App\Domain\Obligations\Actions;

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Obligations\Enums\MemberObligationSource;
use App\Domain\Obligations\Enums\MemberObligationStatus;
use App\Domain\Obligations\Models\MemberObligation;
use App\Domain\Obligations\Models\ObligationType;
use App\Domain\Obligations\Support\ApplicabilityEngine;
use Illuminate\Support\Facades\DB;

/**
 * Propositions d'obligations d'un dossier (F-OBL-02, RG-OBL-02, 03), à l'inscription et à chaque
 * changement de régime, d'effectif ou de secteur : chaque obligation applicable non encore attribuée
 * est proposée ; une proposition devenue sans objet est retirée. Une obligation déjà confirmée n'est
 * jamais retirée d'office : le CGA décide. Un dossier radié ou clos ne reçoit plus de proposition.
 */
class SuggestMemberObligations
{
    public function __construct(private readonly ApplicabilityEngine $engine) {}

    /**
     * @return array{added: int, withdrawn: int}
     */
    public function execute(Member $member): array
    {
        if (! in_array($member->status, [MemberStatus::Draft, MemberStatus::Active, MemberStatus::RadiationPending], true)) {
            return ['added' => 0, 'withdrawn' => 0];
        }

        return DB::transaction(function () use ($member): array {
            $member->load('sector');
            $applicable = $this->engine
                ->forMember($member, ObligationType::query()->inForceFor($member->organization_id)->get())
                ->keyBy('id');
            $open = MemberObligation::query()
                ->where('organization_id', $member->organization_id)
                ->where('member_id', $member->id)
                ->where('status', '!=', MemberObligationStatus::Removed)
                ->lockForUpdate()
                ->get()
                ->keyBy('obligation_type_id');

            $added = 0;
            foreach ($applicable as $typeId => $type) {
                if ($open->has($typeId)) {
                    continue;
                }

                $suggestion = new MemberObligation;
                $suggestion->organization_id = $member->organization_id;
                $suggestion->member_id = $member->id;
                $suggestion->obligation_type_id = $type->id;
                $suggestion->source = MemberObligationSource::Suggested;
                $suggestion->status = MemberObligationStatus::Suggested;
                $suggestion->start_date = today()->toImmutable();
                $suggestion->save();
                $added++;
            }

            $withdrawn = 0;
            foreach ($open as $typeId => $obligation) {
                if ($obligation->status === MemberObligationStatus::Suggested && ! $applicable->has($typeId)) {
                    $obligation->status = MemberObligationStatus::Removed;
                    $obligation->end_date = today()->toImmutable();
                    $obligation->save();
                    $withdrawn++;
                }
            }

            return ['added' => $added, 'withdrawn' => $withdrawn];
        });
    }
}
