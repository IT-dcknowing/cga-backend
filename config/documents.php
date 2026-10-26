<?php

/*
|--------------------------------------------------------------------------
| Modèles de documents (02-TRD §8, 06-IMPLEMENTATION-PLAN étape 0.11)
|--------------------------------------------------------------------------
|
| Modèles de la maquette, nettoyés de toute donnée réelle et balisés ${…}
| (resources/templates). Ce sont les versions 1 provisoires : à l'étape des
| documents, ils sont chargés dans document_templates, où le CNCGA les valide
| et les remplace (F-ADM-02). Pour chaque modèle : ses variables, ses images
| (insérées à la place d'une variable, ou « encadrées » : une image neutre du
| modèle, word/media/{variable}.jpeg, remplacée en gardant son cadre) et les
| lignes répétées (cloneRow) avec leur variable de ligne, et la classe qui
| rassemble les données d'un document de ce type (chaîne de production,
| 02-TRD §8.2).
| Un test vérifie que chaque fichier porte exactement ces variables.
|
*/

use App\Domain\Documents\Composers\InvoiceComposer;
use App\Domain\Documents\Composers\MembershipCertificateComposer;
use App\Domain\Documents\Composers\MembershipContractComposer;
use App\Domain\Documents\Composers\RadiationNoticeComposer;
use App\Domain\Documents\Composers\ReceiptComposer;

$cga = ['cga_name', 'cga_ncc', 'cga_agreement'];
$signature = ['cga_signature', 'cga_stamp', 'qr_code'];

return [

    'templates' => [

        'membership_contract' => [
            'label' => 'Contrat d\'adhésion',
            'format' => 'docx',
            'path' => resource_path('templates/membership_contract.docx'),
            'composer' => MembershipContractComposer::class,
            'images' => $signature,
            'rows' => [],
            'variables' => [
                ...$cga, 'cga_legal_form', 'cga_share_capital', 'cga_address', 'cga_phones', 'cga_email', 'cga_rccm',
                'cga_signatory', 'cga_signatory_name', 'cga_signatory_title',
                'member_name', 'member_legal_form', 'member_sigle', 'member_share_capital', 'member_address', 'member_section',
                'member_parcel', 'member_phone', 'member_fax', 'member_email', 'member_activities', 'member_rccm', 'member_ncc',
                'member_tax_regime', 'member_activity_code', 'member_signatory',
                'document_place', 'document_date',
            ],
        ],

        'membership_certificate' => [
            'label' => 'Attestation d\'adhésion',
            'format' => 'docx',
            'path' => resource_path('templates/membership_certificate.docx'),
            'composer' => MembershipCertificateComposer::class,
            'images' => $signature,
            // Logo dans le cadre flottant de l'en-tête : image word/media/cga_logo.jpeg du modèle.
            'framed_images' => ['cga_logo'],
            'rows' => [],
            'variables' => [
                ...$cga, 'cga_legal_form', 'cga_legal_form_full', 'cga_share_capital', 'cga_address', 'cga_phones', 'cga_email',
                'cga_rccm', 'cga_signatory_name', 'cga_signatory_title',
                'certificate_number', 'certificate_year', 'membership_number', 'membership_date',
                'member_name', 'member_trade_name', 'member_location', 'member_ncc', 'member_legal_form', 'member_tax_regime',
                'member_tax_office', 'member_phone',
                'document_place', 'document_date',
            ],
        ],

        // Modèle provisoire (la maquette n'en a pas) : reçu d'un paiement confirmé (F-PAY-02, RG-PAY-02).
        'receipt' => [
            'label' => 'Reçu',
            'format' => 'docx',
            'path' => resource_path('templates/receipt.docx'),
            'composer' => ReceiptComposer::class,
            'images' => $signature,
            'rows' => [
                'line_label' => ['line_label', 'line_amount'],
            ],
            'variables' => [
                ...$cga, 'cga_legal_form', 'cga_share_capital', 'cga_address', 'cga_phones', 'cga_email', 'cga_rccm',
                'cga_signatory_name', 'cga_signatory_title',
                'receipt_number', 'member_name', 'member_ncc', 'member_number',
                'payment_amount', 'payment_amount_words', 'payment_date', 'payment_method', 'payment_reference',
                'document_place', 'document_date',
            ],
        ],

        // Modèle provisoire (la maquette n'en a pas) : facture certifiée FNE (F-PAY-05, RG-FNE-02), produite
        // seulement après la certification, avec le n° FNE, le QR de certification et le cachet fiscal.
        'invoice' => $invoice = [
            'label' => 'Facture',
            'format' => 'docx',
            'path' => resource_path('templates/invoice.docx'),
            'composer' => InvoiceComposer::class,
            'images' => [...$signature, 'fne_qr'],
            'rows' => [
                'line_label' => ['line_label', 'line_quantity', 'line_unit_amount', 'line_tax_rate', 'line_amount'],
            ],
            'variables' => [
                ...$cga, 'cga_legal_form', 'cga_share_capital', 'cga_address', 'cga_phones', 'cga_email', 'cga_rccm',
                'cga_signatory_name', 'cga_signatory_title',
                'invoice_title', 'invoice_number', 'invoice_date', 'invoice_reference',
                'member_name', 'member_ncc', 'member_number', 'member_address',
                'amount_excl_tax', 'tax_amount', 'amount_incl_tax', 'amount_words',
                'fne_number', 'fne_certified_at', 'fne_stamp',
                'document_place', 'document_date',
            ],
        ],

        // Avoir certifié FNE (RG-FNE-01) : même modèle provisoire que la facture, titre et référence de
        // la facture annulée en plus ; le CNCGA peut lui donner un modèle propre (F-ADM-02).
        'credit_note' => [...$invoice, 'label' => 'Avoir'],

        // Modèle provisoire (la maquette n'en a pas) : courrier de notification de la radiation au CDI de
        // rattachement (F-ADH-06, effet 3), remis par le CGA.
        'radiation_notice' => [
            'label' => 'Courrier de radiation',
            'format' => 'docx',
            'path' => resource_path('templates/radiation_notice.docx'),
            'composer' => RadiationNoticeComposer::class,
            'images' => $signature,
            'rows' => [],
            'variables' => [
                ...$cga, 'cga_legal_form', 'cga_share_capital', 'cga_address', 'cga_phones', 'cga_email', 'cga_rccm',
                'cga_signatory_name', 'cga_signatory_title',
                'notice_number', 'tax_office_name', 'member_name', 'member_ncc', 'membership_number', 'adhesion_date',
                'member_tax_regime', 'radiation_effective_date', 'radiation_initiator', 'radiation_reason',
                'radiation_validated_on', 'radiation_reference',
                'document_place', 'document_date',
            ],
        ],

        'quarterly_report_docx' => [
            'label' => 'Rapport trimestriel (Word)',
            'format' => 'docx',
            'path' => resource_path('templates/quarterly_report_docx.docx'),
            'images' => ['cga_signature', 'cga_stamp', 'qr_code'],
            'rows' => [
                'director_name' => ['director_index', 'director_name', 'director_profession', 'director_function', 'director_start_date', 'director_experience'],
                'employee_name' => ['employee_index', 'employee_name', 'employee_profession', 'employee_function', 'employee_start_date', 'employee_cnps'],
                'occasional_name' => ['occasional_index', 'occasional_name', 'occasional_diploma', 'occasional_function', 'occasional_since'],
                'movement_name' => ['movement_name', 'movement_ncc', 'movement_regime', 'movement_number', 'movement_status'],
            ],
            'variables' => [
                ...$cga, 'cga_address', 'cga_seat', 'cga_location', 'cga_contacts', 'cga_tax_service', 'cga_legal_form_capital',
                'cga_history', 'cga_previous_year_revenue',
                'report_quarter', 'report_date', 'regional_directorate', 'document_place',
                'res_rooms', 'res_training_rooms', 'res_furniture', 'res_computers', 'res_software', 'res_projectors', 'res_vehicles',
                'trainings', 'smt_count', 'balance_sheet_count', 'meetings', 'difficulties', 'suggestions', 'forecasts',
                'active_tee', 'active_rme', 'active_total', 'new_tee', 'new_rme', 'new_total',
                'terminated_tee', 'terminated_rme', 'terminated_total',
                'tax_tee', 'tax_rme', 'tax_vat', 'tax_its', 'tax_cnps', 'tax_total',
                'signatory_name', 'signatory_title', 'verification_reference',
            ],
        ],

        // Classeur officiel SP-CGA, 14 feuilles (02-TRD §8.3) : export de compatibilité.
        'quarterly_report_xlsx' => [
            'label' => 'Rapport trimestriel (classeur officiel)',
            'format' => 'xlsx',
            'path' => resource_path('templates/quarterly_report_xlsx.xlsx'),
            'sheets' => 14,
            'variables' => [
                'report_quarter_label', 'report_date', 'period_start', 'period_end', 'tax_office_name',
                ...$cga, 'cga_seat', 'cga_location', 'cga_contacts', 'regional_directorate', 'cga_tax_service',
                'cga_legal_form_capital', 'cga_history', 'cga_previous_year_revenue',
                'director_name', 'director_nationality', 'director_profession', 'director_diplomas', 'director_function',
                'director_start_date', 'director_practice_structure', 'director_practice_function', 'director_practice_years',
                'employee_name', 'employee_nationality', 'employee_profession', 'employee_diplomas', 'employee_function',
                'employee_start_date', 'employee_cnps', 'employee_its', 'employee_igr',
                'occasional_name', 'occasional_nationality', 'occasional_profession', 'occasional_diplomas', 'occasional_function',
                'occasional_start_date', 'occasional_duration',
                'res_rooms', 'res_training_rooms', 'res_furniture', 'res_computers', 'res_software', 'res_projectors',
                'res_vehicles', 'res_other',
                'trainings', 'training_beneficiaries', 'synthetic_client_name', 'synthetic_client_ncc', 'rsi_client_name', 'rsi_client_ncc',
                'member_name', 'member_ncc', 'member_activity', 'member_address', 'membership_number_date', 'member_section_parcel',
                'member_turnover', 'registration_date', 'termination_date_reason',
            ],
        ],

    ],

    // Graphiques natifs du rapport modernisé (02-TRD §8.4, décision T-21) : taille (EMU, 1 cm =
    // 360 000), palette de la charte (04-UX-UI : primary, série secondaire, accent, info…), légende
    // sous le graphique. La même palette sert aux graphiques de l'écran.
    'charts' => [
        'width' => 5_760_000,
        'height' => 3_240_000,
        'palette' => ['1E6A4E', '4E9273', 'E5732A', '2B4C7E', '9A4A12', '5C6B62'],
        'legend_position' => 'b',
    ],

    // Dossier des fichiers temporaires de PhpWord, un sous-dossier par rendu.
    'temporary_path' => storage_path('framework/phpword'),

    // Dossier de travail de la chaîne de production (modèle, images, DOCX), un sous-dossier jetable par document.
    'work_path' => storage_path('framework/documents'),

    // Page publique de vérification (F-DOC-04) : le QR code d'un document mène à cette adresse suivie de son jeton.
    'verification_url' => rtrim((string) (env('DOCUMENT_VERIFICATION_URL') ?: env('FRONTEND_URL', env('APP_URL', 'http://localhost')).'/verifier'), '/'),

    // Consultation de la page publique (RG-DOC-09, 02-TRD §6.6) : l'adresse IP n'est gardée que tronquée à
    // son réseau (longueur du préfixe en bits).
    'verification_ip_prefix' => [
        'ipv4' => (int) env('DOCUMENT_VERIFICATION_IPV4_PREFIX', 24),
        'ipv6' => (int) env('DOCUMENT_VERIFICATION_IPV6_PREFIX', 48),
    ],

    // QR code de vérification (RG-DOC-02) : taille et marge de l'image produite (pixels).
    'qr' => ['size' => 240, 'margin' => 8],

    // Taille des images insérées (pixels) : logo, signature et cachet du signataire, QR de vérification (RG-DOC-02).
    'images' => [
        'cga_signature' => ['width' => 96, 'height' => 40],
        'cga_stamp' => ['width' => 56, 'height' => 56],
        'qr_code' => ['width' => 64, 'height' => 64],
    ],

];
