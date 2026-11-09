<?php

namespace App\Domain\Obligations\Actions;

use App\Domain\Members\Models\Member;
use App\Domain\Obligations\Enums\InstanceKind;
use App\Domain\Obligations\Enums\ObligationCategory;
use App\Domain\Obligations\Enums\TaxNature;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Domain\Obligations\Support\InstanceAmounts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * « + Autre administration » (F-OBL-04) : ligne de paiement saisie librement par le CGA, hors catalogue
 * (administration, libellé, montant dû, échéance) ; sa période est son échéance.
 */
class AddOtherAuthorityInstance
{
    public function execute(Member $member, string $authority, ?string $label, int $amountDue, CarbonImmutable $dueDate): ObligationInstance
    {
        return DB::transaction(function () use ($member, $authority, $label, $amountDue, $dueDate): ObligationInstance {
            $instance = new ObligationInstance;
            $instance->organization_id = $member->organization_id;
            $instance->member_id = $member->id;
            $instance->label = $label ?? $authority;
            $instance->category = ObligationCategory::Other;
            $instance->kind = InstanceKind::Payment;
            $instance->authority_name = $authority;
            $instance->period_label = $dueDate->toDateString();
            $instance->period_start = $dueDate;
            $instance->period_end = $dueDate;
            $instance->due_date = $dueDate;
            $instance->tax_nature = TaxNature::Other;
            $instance->amount_due = $amountDue;
            $instance->amount_paid = 0;
            $instance->status = InstanceAmounts::status($amountDue, 0);
            $instance->save();

            return $instance;
        });
    }
}
