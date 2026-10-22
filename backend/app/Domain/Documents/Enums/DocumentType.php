<?php

namespace App\Domain\Documents\Enums;

use App\Support\Enums\HasLabel;

/**
 * Type d'un document produit ou d'un modèle (F-DOC-01, 05-BACKEND-SCHEMA §8) ; « uploaded » : document déposé par le CGA (F-DOC-05), sans modèle.
 */
enum DocumentType: string
{
    use HasLabel;

    case MembershipContract = 'membership_contract';
    case MembershipCertificate = 'membership_certificate';
    case Receipt = 'receipt';
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
    case RadiationNotice = 'radiation_notice';
    case QuarterlyReportDocx = 'quarterly_report_docx';
    case QuarterlyReportModern = 'quarterly_report_modern';
    case QuarterlyReportXlsx = 'quarterly_report_xlsx';
    case ReportTransmissionSlip = 'report_transmission_slip';
    case DuesReceipt = 'dues_receipt';
    case DuesInvoice = 'dues_invoice';
    case TrainingCertificate = 'training_certificate';
    case BankingFile = 'banking_file';
    case Uploaded = 'uploaded';
}
