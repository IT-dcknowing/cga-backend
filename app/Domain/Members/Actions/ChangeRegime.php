<?php

namespace App\Domain\Members\Actions;

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\MemberRegimeChange;
use App\Domain\Users\Models\User;
use App\Support\Files\StorageService;
use App\Support\Http\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * « Changer de régime » (F-ADH-05) : nouveau régime, date d'effet, motif et justificatif
 * (RG-ADH-06b), avec un historique daté qui alimente les états de surclassement du rapport. Le
 * régime de départ y est inscrit au premier changement. Au portefeuille CGA, le passage au RSI ou au
 * RNI est permis (reclassement de l'administration) et ouvre l'alerte « Radiation requise » à la
 * date d'effet (rsi_since, RG-ADH-08, 09) ; le retour au RME ou au TEE la referme. Rien n'est radié ni
 * bloqué automatiquement (CR-04, RG-ADH-18).
 */
class ChangeRegime
{
    public function __construct(private readonly StorageService $storage) {}

    /**
     * @throws BusinessRuleException member_draft, member_closed
     * @throws ValidationException régime inchangé, date d'effet antérieure au régime en cours
     */
    public function execute(Member $member, TaxRegime $regime, CarbonImmutable $effectiveDate, string $reason, UploadedFile $justification, User $actor): Member
    {
        return DB::transaction(function () use ($member, $regime, $effectiveDate, $reason, $justification, $actor): Member {
            $member = Member::query()->lockForUpdate()->findOrFail($member->id);
            if ($member->status === MemberStatus::Draft) {
                throw new BusinessRuleException('member_draft');
            }
            if (in_array($member->status, [MemberStatus::Radiated, MemberStatus::Closed], true)) {
                throw new BusinessRuleException('member_closed');
            }
            if ($regime === $member->regime) {
                throw ValidationException::withMessages(['regime' => __('members.regime_unchanged')]);
            }
            if ($effectiveDate->lt($member->regime_since)) {
                throw ValidationException::withMessages(['effective_date' => __('members.regime_effective_date_before', ['date' => $member->regime_since->format('d/m/Y')])]);
            }

            if (! MemberRegimeChange::query()->where('member_id', $member->id)->exists()) {
                $this->record($member, $member->regime, CarbonImmutable::parse($member->regime_since), __('members.initial_regime'), null, $member->validated_by);
            }
            $file = $this->storage->storeUpload($justification, 'justifications', $member->organization_id, $actor->id);
            $this->record($member, $regime, $effectiveDate, $reason, $file->id, $actor->id);

            if ($member->portfolio === Portfolio::Cga) {
                $member->rsi_since = match (true) {
                    ! $regime->isReal() => null,
                    $member->regime->isReal() => $member->rsi_since,
                    default => $effectiveDate,
                };
            }
            $member->regime = $regime;
            $member->regime_since = $effectiveDate;
            $member->updated_by = $actor->id;
            $member->save();

            return $member->refresh();
        });
    }

    private function record(Member $member, TaxRegime $regime, CarbonImmutable $effectiveDate, string $reason, ?string $fileId, ?string $authorId): void
    {
        $change = new MemberRegimeChange;
        $change->organization_id = $member->organization_id;
        $change->member_id = $member->id;
        $change->regime = $regime;
        $change->effective_date = $effectiveDate;
        $change->reason = $reason;
        $change->justification_file_id = $fileId;
        $change->created_by = $authorId;
        $change->save();
    }
}
