<?php

namespace App\Domain\Trainings\Enums;

use App\Support\Enums\HasLabel;

/**
 * Paiement d'une formation payante (RG-FOR-06) : sans objet (gratuite), à payer, payé (encaissé hors plateforme, reçu produit).
 */
enum TrainingPaymentStatus: string
{
    use HasLabel;

    case NotApplicable = 'not_applicable';
    case ToPay = 'to_pay';
    case Paid = 'paid';
}
