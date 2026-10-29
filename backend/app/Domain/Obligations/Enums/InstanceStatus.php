<?php

namespace App\Domain\Obligations\Enums;

use App\Support\Enums\HasLabel;

/**
 * Statut d'une échéance (F-OBL-04) : déclaration à faire / faite ; paiement à payer / payé en partie / payé
 * (RG-OBL-07) ; diligence non établie / en cours / établie ; annulée.
 */
enum InstanceStatus: string
{
    use HasLabel;

    case Todo = 'todo';
    case Done = 'done';
    case ToPay = 'to_pay';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case NotEstablished = 'not_established';
    case InProgress = 'in_progress';
    case Established = 'established';
    case Cancelled = 'cancelled';
}
