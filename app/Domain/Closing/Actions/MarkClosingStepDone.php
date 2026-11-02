<?php

namespace App\Domain\Closing\Actions;

use App\Domain\Closing\Enums\ClosingCampaignStatus;
use App\Domain\Closing\Enums\ClosingStep;
use App\Domain\Closing\Models\ClosingCampaignItem;
use App\Domain\Users\Models\User;
use App\Support\Files\StorageService;
use App\Support\Http\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * « Marquer l'étape faite » pour un dossier de la campagne de clôture (F-OBL-08, RG-OBL-10) : date ;
 * pour le dépôt légal, référence et accusé. Les étapes se font dans l'ordre (décision 0006) ; une
 * étape faite ne se refait pas ; une campagne close ne change plus. L'auteur est gardé au journal des
 * modifications.
 */
class MarkClosingStepDone
{
    public function __construct(private readonly StorageService $storage) {}

    /**
     * @throws BusinessRuleException closing_campaign_closed, closing_step_already_done, closing_step_out_of_order
     */
    public function execute(ClosingCampaignItem $item, ClosingStep $step, CarbonImmutable $doneOn, ?string $reference, ?UploadedFile $receipt, User $actor): ClosingCampaignItem
    {
        return DB::transaction(function () use ($item, $step, $doneOn, $reference, $receipt, $actor): ClosingCampaignItem {
            $item = ClosingCampaignItem::query()->with('campaign')->lockForUpdate()->findOrFail($item->id);
            if ($item->campaign->status === ClosingCampaignStatus::Closed) {
                throw new BusinessRuleException('closing_campaign_closed');
            }
            $refusal = $item->stepRefusal($step);
            if ($refusal !== null) {
                throw new BusinessRuleException($refusal, self::refusalMessage($item, $refusal));
            }

            $item->setAttribute($step->column(), $doneOn);
            if ($step === ClosingStep::Filing) {
                $item->filing_reference = $reference;
                $item->filing_file_id = $receipt === null ? null : $this->storage->storeUpload($receipt, 'closing', $item->organization_id, $actor->id)->id;
            }
            $item->save();

            return $item;
        });
    }

    /** Message du refus, avec l'étape à faire d'abord quand l'ordre n'est pas respecté. */
    public static function refusalMessage(ClosingCampaignItem $item, string $refusal): string
    {
        return (string) __("errors.{$refusal}", ['step' => (string) $item->nextStep()?->label()]);
    }
}
