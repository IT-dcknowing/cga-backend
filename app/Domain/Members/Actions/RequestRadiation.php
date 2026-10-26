<?php

namespace App\Domain\Members\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Members\Data\RadiationDemand;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Enums\RadiationOutcome;
use App\Domain\Members\Enums\RadiationStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\Radiation;
use App\Domain\Members\Support\RadiationRules;
use App\Domain\Users\Models\User;
use App\Support\Files\StorageService;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * « Demander la radiation » (F-ADH-06), premier temps de RG-ADH-17 : initiateur, motif permis pour
 * cet initiateur, pièce exigée selon le motif, référence de la demande au SP-CGA si le CGA en est à
 * l'origine (config/radiations.php). Le dossier passe en « radiation en cours » : il reste suivi et
 * bloque toujours l'adhésion dans un autre CGA (RG-ADH-05). Une radiation initiée par le contribuable
 * ou consécutive à un décès peut être validée dans la même opération. Seul un utilisateur habilité
 * l'appelle, depuis sa route (RG-ADH-18).
 */
class RequestRadiation
{
    public function __construct(
        private readonly RadiationRules $rules,
        private readonly StorageService $storage,
        private readonly ValidateRadiation $validate,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{outcome: RadiationOutcome, reference: string|null}|null  $immediate  validation dans la même opération
     *
     * @throws BusinessRuleException radiation_cga_only, radiation_already_requested, member_not_active
     * @throws ValidationException motif non permis, pièce manquante, référence SP-CGA hors initiative du CGA,
     *                             date d'effet antérieure à l'adhésion, validation immédiate non permise
     */
    public function execute(Member $member, RadiationDemand $demand, ?UploadedFile $proof, ?array $immediate, User $actor): Radiation
    {
        return DB::transaction(function () use ($member, $demand, $proof, $immediate, $actor): Radiation {
            $member = Member::query()->lockForUpdate()->findOrFail($member->id);
            if ($member->portfolio !== Portfolio::Cga) {
                throw new BusinessRuleException('radiation_cga_only');
            }
            if ($member->status === MemberStatus::RadiationPending) {
                throw new BusinessRuleException('radiation_already_requested');
            }
            if ($member->status !== MemberStatus::Active) {
                throw new BusinessRuleException('member_not_active');
            }
            $this->check($member, $demand, $proof, $immediate !== null);

            $radiation = new Radiation;
            $radiation->organization_id = $member->organization_id;
            $radiation->member_id = $member->id;
            $radiation->initiator = $demand->initiator;
            $radiation->reason = $demand->reason;
            $radiation->reason_other = $demand->reasonOther;
            $radiation->status = RadiationStatus::Requested;
            $radiation->effective_date = $demand->effectiveDate;
            $radiation->observation = $demand->observation;
            $radiation->proof_file_id = $proof === null ? null : $this->storage->storeUpload($proof, 'radiations', $member->organization_id, $actor->id)->id;
            $radiation->sp_request_reference = $demand->spRequestReference;
            $radiation->requested_by = $actor->id;
            $radiation->requested_at = now()->toImmutable();
            $radiation->save();

            $member->status = MemberStatus::RadiationPending;
            $member->updated_by = $actor->id;
            $member->save();
            $this->audit->record('radiation_requested', $member, context: ['radiation_id' => $radiation->id, 'initiator' => $demand->initiator->value, 'reason' => $demand->reason->value]);

            if ($immediate !== null) {
                return $this->validate->execute($member, $immediate['outcome'], $immediate['reference'], $actor);
            }

            return $radiation->refresh();
        });
    }

    /**
     * Table des radiations du PRD F-ADH-06 : toutes les erreurs de saisie ensemble (422).
     *
     * @throws ValidationException
     */
    private function check(Member $member, RadiationDemand $demand, ?UploadedFile $proof, bool $immediate): void
    {
        $errors = [];
        if (! $this->rules->allows($demand->initiator, $demand->reason)) {
            $errors['reason'] = __('members.radiation.reason_not_allowed', ['initiator' => $demand->initiator->label()]);
        }
        $requiredProof = $this->rules->proof($demand->initiator, $demand->reason);
        if ($requiredProof !== null && $proof === null) {
            $errors['proof'] = __('members.radiation.proof_required', ['proof' => __("members.radiation.proofs.{$requiredProof}")]);
        }
        if ($demand->spRequestReference !== null && ! $this->rules->acceptsSpRequestReference($demand->initiator)) {
            $errors['sp_request_reference'] = __('members.radiation.sp_request_reference_cga_only');
        }
        if ($member->adhesion_date !== null && $demand->effectiveDate->lt($member->adhesion_date)) {
            $errors['effective_date'] = __('members.radiation.effective_date_before_adhesion', ['date' => $member->adhesion_date->format('d/m/Y')]);
        }
        if ($immediate && ! $this->rules->allowsImmediateValidation($demand->initiator, $demand->reason)) {
            $errors['validate_now'] = __('members.radiation.immediate_not_allowed');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
