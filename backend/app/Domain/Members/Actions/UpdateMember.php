<?php

namespace App\Domain\Members\Actions;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Support\MemberFields;
use App\Domain\Members\Support\MembershipExclusivity;
use App\Domain\Payments\Actions\SyncEntryFee;
use App\Domain\Users\Models\User;
use App\Support\Files\StorageService;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Modification d'un dossier (F-ADH-04) : tous les champs, avec leur historique (journal des
 * modifications). Sur un dossier validé, changer la raison sociale ou le NCC exige un justificatif,
 * joint à l'entrée du journal (RG-ADH-06b) ; le régime passe par le changement de régime (F-ADH-05).
 * Un dossier radié ou clos ne se modifie plus : un retour est une nouvelle adhésion (RG-ADH-13).
 */
class UpdateMember
{
    /**
     * Champs d'identité qui exigent un justificatif (RG-ADH-06b, hors régime).
     */
    private const JUSTIFIED = ['company_name', 'ncc'];

    public function __construct(
        private readonly MemberFields $fields,
        private readonly MembershipExclusivity $exclusivity,
        private readonly SaveReferenceRevenue $revenue,
        private readonly StorageService $storage,
        private readonly SyncEntryFee $entryFee,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  saisie validée de la fiche
     * @param  array{year: int, amount: int, kind: string}|null  $revenue
     *
     * @throws BusinessRuleException member_closed, ncc_already_registered, membership_exclusive
     * @throws ValidationException justificatif manquant, régime à changer par F-ADH-05
     */
    public function execute(Member $member, array $attributes, ?array $revenue, ?bool $contractConfirmed, ?UploadedFile $justification, User $actor): Member
    {
        $attributes = $this->fields->prepare($attributes);

        return DB::transaction(function () use ($member, $attributes, $revenue, $contractConfirmed, $justification, $actor): Member {
            $member = Member::query()->lockForUpdate()->findOrFail($member->id);
            if (in_array($member->status, [MemberStatus::Radiated, MemberStatus::Closed], true)) {
                throw new BusinessRuleException('member_closed');
            }

            $draft = $member->status === MemberStatus::Draft;
            if (! $draft && isset($attributes['regime']) && $attributes['regime'] !== $member->regime->value) {
                throw ValidationException::withMessages(['regime' => __('members.regime_change_required')]);
            }
            $justified = array_values(array_filter(self::JUSTIFIED, static fn (string $field): bool => array_key_exists($field, $attributes) && $attributes[$field] !== $member->getAttribute($field)));
            if (! $draft && $justified !== [] && $justification === null) {
                throw ValidationException::withMessages(['justification' => __('members.justification_required', ['fields' => implode(', ', array_map(static fn (string $f): string => __("members.fields.{$f}"), $justified))])]);
            }

            $ncc = (string) ($attributes['ncc'] ?? $member->ncc);
            if ($ncc !== $member->ncc) {
                AssertNccAvailable::here($ncc, $member);
                if ($member->portfolio === Portfolio::Cga && $this->exclusivity->enrolledElsewhere($ncc, $member->organization_id)) {
                    throw new BusinessRuleException('membership_exclusive');
                }
            }

            $member->fill($draft ? $attributes : array_diff_key($attributes, ['regime' => true, 'regime_since' => true]));
            if ($member->portfolio === Portfolio::Cga) {
                $member->referrer_id = array_key_exists('referrer_id', $attributes) ? $attributes['referrer_id'] : $member->referrer_id;
                $member->cga_contract_signed_on = array_key_exists('cga_contract_signed_on', $attributes) ? $attributes['cga_contract_signed_on'] : $member->cga_contract_signed_on;
                if ($contractConfirmed !== null) {
                    $member->cga_contract_confirmed_by = $contractConfirmed ? ($member->cga_contract_confirmed_by ?? $actor->id) : null;
                }
            }
            $member->updated_by = $actor->id;
            $member->save();

            if ($justification !== null && $justified !== []) {
                $this->attach($member, $justification, $actor);
            }
            if ($revenue !== null) {
                $this->revenue->execute($member, $revenue);
            }
            $this->entryFee->execute($member);

            return $member->refresh();
        });
    }

    /**
     * Justificatif joint à l'entrée du journal qui trace la modification (RG-ADH-06b).
     */
    private function attach(Member $member, UploadedFile $justification, User $actor): void
    {
        $file = $this->storage->storeUpload($justification, 'justifications', $member->organization_id, $actor->id);
        $entry = AuditLog::query()
            ->where('subject_type', $member->getMorphClass())
            ->where('subject_id', $member->id)
            ->where('event', 'updated')
            ->latest('id')
            ->firstOrFail();

        DB::table('audit_attachments')->insert(['audit_log_id' => $entry->id, 'file_id' => $file->id]);
    }
}
