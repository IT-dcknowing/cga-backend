<?php

namespace App\Domain\Payments\Support;

use App\Domain\Payments\Models\MemberCharge;
use App\Support\Config\SettingsService;

/**
 * Montants d'une somme due (F-PAY-01, F-PAY-05) : le barème et les montants contractuels sont hors
 * taxe ; la TVA, au taux invoices.vat_rate du CGA (18 % par défaut, réglable par le CNCGA et par chaque
 * CGA), s'y ajoute dès la création de la somme due, arrondie au franc ; le montant dû est toutes taxes
 * comprises (décision du 23/10/2026). Le taux est figé sur la somme due.
 */
class ChargeAmounts
{
    /**
     * Taux de chaque CGA, lu une fois par instance (génération par lots).
     *
     * @var array<string, float>
     */
    private array $rates = [];

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Fixe le hors taxe, le taux, la TVA et le montant dû d'une somme due.
     */
    public function apply(MemberCharge $charge, int $amountExclTax, string $organizationId): MemberCharge
    {
        $rate = $this->rate($organizationId);
        $tax = (int) round($amountExclTax * $rate / 100, 0, PHP_ROUND_HALF_UP);

        $charge->amount_excl_tax = $amountExclTax;
        $charge->tax_rate = $rate;
        $charge->tax_amount = $tax;
        $charge->amount = $amountExclTax + $tax;

        return $charge;
    }

    public function rate(string $organizationId): float
    {
        return $this->rates[$organizationId] ??= (float) $this->settings->get('invoices.vat_rate', $organizationId);
    }
}
