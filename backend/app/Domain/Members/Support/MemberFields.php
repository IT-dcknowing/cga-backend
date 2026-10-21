<?php

namespace App\Domain\Members\Support;

use App\Domain\Members\Enums\FeeKind;
use App\Domain\Members\Enums\FeesPeriodicity;
use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Members\Models\FeeSchedule;
use App\Support\Phones\PhoneNormalizer;
use Illuminate\Support\Str;

/**
 * Saisie d'un dossier mise en forme (F-ADH-02) : raison sociale en majuscules, sigle par défaut
 * (initiales), NCC normalisé, téléphones au format E.164, WhatsApp par défaut = téléphone, montants
 * proposés par le barème du CGA quand ils ne sont pas saisis.
 */
class MemberFields
{
    public function __construct(private readonly PhoneNormalizer $phones) {}

    /**
     * @param  array<string, mixed>  $attributes  saisie validée
     * @return array<string, mixed>
     */
    public function prepare(array $attributes): array
    {
        if (isset($attributes['company_name'])) {
            $attributes['company_name'] = Str::upper(trim((string) $attributes['company_name']));
            if (blank($attributes['sigle'] ?? null)) {
                $attributes['sigle'] = $this->initials($attributes['company_name']);
            }
        }
        if (isset($attributes['ncc'])) {
            $attributes['ncc'] = Ncc::normalize((string) $attributes['ncc']);
        }
        $attributes = $this->phones->normalizeFields($attributes, ['phone', 'whatsapp']);
        if (isset($attributes['phone']) && blank($attributes['whatsapp'] ?? null)) {
            $attributes['whatsapp'] = $attributes['phone'];
        }

        return $attributes;
    }

    /**
     * Montants du barème du CGA en vigueur (F-PAY-01) pour un régime : droit d'adhésion, honoraires et
     * leur périodicité ; seulement ceux que la saisie n'a pas donnés.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function withSchedule(array $attributes, TaxRegime $regime): array
    {
        $current = static fn (FeeKind $kind, ?TaxRegime $forRegime): ?FeeSchedule => FeeSchedule::query()
            ->where('kind', $kind)
            ->where('regime', $forRegime)
            ->where('valid_from', '<=', today())
            ->orderByDesc('valid_from')
            ->first();

        $entry = $current(FeeKind::EntryFee, null);
        $fees = in_array($regime, [TaxRegime::Rme, TaxRegime::Tee], true) ? $current(FeeKind::Fees, $regime) : null;

        $attributes['entry_fee_amount'] ??= $entry->amount ?? 0;
        $attributes['fees_amount'] ??= $fees->amount ?? 0;
        $attributes['fees_periodicity'] ??= $fees?->periodicity->value ?? FeesPeriodicity::Monthly->value;

        return $attributes;
    }

    private function initials(string $name): string
    {
        $words = preg_split('/[\s\-&\'.,]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return mb_substr(implode('', array_map(static fn (string $word): string => mb_substr($word, 0, 1), $words)), 0, 50);
    }
}
