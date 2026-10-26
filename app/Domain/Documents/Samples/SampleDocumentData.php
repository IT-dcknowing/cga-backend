<?php

namespace App\Domain\Documents\Samples;

use App\Domain\Reports\Charts\ReportChart;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Contracts\Config\Repository;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use RuntimeException;

/**
 * Données fictives des échantillons de documents (étapes 0.11 et 0.12, contrôle visuel par la QA) :
 * un CGA, un adhérent et un rapport inventés, des images de remplacement (logo, signature, cachet,
 * QR) et les trois graphiques du rapport modernisé (secteurs, barres, courbe).
 */
class SampleDocumentData
{
    public function __construct(private readonly Repository $config) {}

    /**
     * @return array<string, string>
     */
    public function values(string $type): array
    {
        $cga = [
            'cga_name' => 'CGA EXEMPLE ABIDJAN', 'cga_ncc' => '0000000 A', 'cga_agreement' => '000/SEPMBPE/DGI du 2 janvier 2025',
            'cga_legal_form' => 'SARL', 'cga_legal_form_full' => 'Société à responsabilité limitée', 'cga_share_capital' => '1 000 000 FCFA',
            'cga_address' => 'Abidjan, Plateau, avenue Exemple', 'cga_phones' => '+225 27 20 00 00 00', 'cga_email' => 'contact@cga-exemple.ci',
            'cga_rccm' => 'CI-ABJ-2025-B-00000', 'cga_signatory' => 'Madame EXEMPLE Awa, Gérante',
            'cga_signatory_name' => 'EXEMPLE Awa', 'cga_signatory_title' => 'La Gérante',
            'cga_seat' => 'ABIDJAN – PLATEAU', 'cga_location' => 'Avenue Exemple', 'cga_contacts' => '+225 27 20 00 00 00 · contact@cga-exemple.ci',
            'cga_tax_service' => 'Service d\'assiette Exemple', 'cga_legal_form_capital' => 'SARL au capital de 1 000 000 FCFA',
            'cga_history' => 'Centre fictif créé pour les essais de CGA Connect.', 'cga_previous_year_revenue' => '25 000 000 FCFA',
            'document_place' => 'Abidjan', 'document_date' => '20 octobre 2026',
        ];
        $member = [
            'member_name' => 'ENTREPRISE EXEMPLE', 'member_trade_name' => 'ENTREPRISE EXEMPLE', 'member_legal_form' => 'SARL',
            'member_sigle' => 'E.E. SARL', 'member_share_capital' => '1 000 000 FCFA', 'member_address' => 'Abidjan, Cocody',
            'member_section' => '000', 'member_parcel' => '00', 'member_phone' => '+225 07 00 00 00 00', 'member_fax' => '—',
            'member_email' => 'contact@entreprise-exemple.ci', 'member_activities' => 'Commerce général',
            'member_rccm' => 'CI-ABJ-2025-B-11111', 'member_ncc' => '1111111 B', 'member_tax_regime' => 'Réel simplifié (RSI)',
            'member_activity_code' => 'COM001', 'member_signatory' => 'EXEMPLE Koffi, Gérant', 'member_location' => 'Abidjan, Cocody',
            'member_tax_office' => 'CDI Exemple', 'membership_number' => 'EXA-2026-B00001', 'membership_date' => '02/01/2026',
            'certificate_number' => 'ATT-EXA-2026-00001', 'certificate_year' => '2026',
        ];
        $report = [
            'report_quarter' => '3e trimestre 2026', 'report_date' => '20/10/2026', 'regional_directorate' => 'DR Abidjan Nord',
            'res_rooms' => '3', 'res_training_rooms' => '1', 'res_furniture' => '6', 'res_computers' => '5', 'res_software' => '2',
            'res_projectors' => '1', 'res_vehicles' => '0',
            'trainings' => 'Deux sessions sur la facture normalisée électronique.', 'smt_count' => '42', 'balance_sheet_count' => '12',
            'meetings' => 'Une rencontre avec le CDI de rattachement.', 'difficulties' => 'Aucune difficulté notable.',
            'suggestions' => 'Renforcer la formation des adhérents.', 'forecasts' => 'Campagne d\'adhésion du trimestre suivant.',
            'active_tee' => '120', 'active_rme' => '45', 'active_total' => '165', 'new_tee' => '8', 'new_rme' => '3', 'new_total' => '11',
            'terminated_tee' => '2', 'terminated_rme' => '1', 'terminated_total' => '3',
            'tax_tee' => '1 000 000', 'tax_rme' => '2 000 000', 'tax_vat' => '1 500 000', 'tax_its' => '500 000', 'tax_cnps' => '700 000',
            'tax_total' => '5 700 000', 'signatory_name' => 'EXEMPLE Awa', 'signatory_title' => 'Gérante',
            'verification_reference' => 'RPT-EXA-2026-T3-V1',
        ];

        $receipt = [
            'receipt_number' => 'REC-EXA-2026-00001', 'member_name' => 'ENTREPRISE EXEMPLE', 'member_ncc' => '1111111 B',
            'member_number' => 'EXA-2026-B00001', 'payment_amount' => '40 000 FCFA', 'payment_amount_words' => 'quarante mille francs CFA',
            'payment_date' => '20 octobre 2026', 'payment_method' => 'Mobile money', 'payment_reference' => 'OM-000000',
        ];
        $notice = [
            'notice_number' => 'NOT-EXA-2026-00001', 'tax_office_name' => 'CDI Exemple', 'member_name' => 'BOUTIQUE EXEMPLE',
            'member_ncc' => '2222222 C', 'membership_number' => 'EXA-2026-A00007', 'adhesion_date' => '02/01/2026',
            'member_tax_regime' => 'TEE — entreprenant', 'radiation_effective_date' => '30 septembre 2026',
            'radiation_initiator' => 'Contribuable', 'radiation_reason' => 'Cessation d\'activité',
            'radiation_validated_on' => '15 octobre 2026', 'radiation_reference' => 'REF-000',
        ];

        $invoice = [
            'invoice_title' => $type === 'credit_note' ? 'FACTURE D\'AVOIR' : 'FACTURE', 'invoice_number' => 'FAC-EXA-2026-00001',
            'invoice_date' => '20 octobre 2026', 'invoice_reference' => $type === 'credit_note' ? 'Avoir sur la facture n° FAC-EXA-2026-00000 du 1er octobre 2026' : '',
            'member_name' => 'ENTREPRISE EXEMPLE', 'member_ncc' => '1111111 B', 'member_number' => 'EXA-2026-B00001',
            'member_address' => 'Abidjan, Cocody', 'amount_excl_tax' => '40 000 FCFA', 'tax_amount' => '7 200 FCFA',
            'amount_incl_tax' => '47 200 FCFA', 'amount_words' => 'quarante-sept mille deux cents francs CFA',
            'fne_number' => 'FNE-EXEMPLE-0001', 'fne_certified_at' => '20 octobre 2026 à 10 h 00', 'fne_stamp' => 'CACHET-EXEMPLE',
        ];

        return match ($type) {
            'membership_contract', 'membership_certificate' => [...$cga, ...$member],
            'invoice', 'credit_note' => [...$cga, ...$invoice],
            'receipt' => [...$cga, ...$receipt],
            'radiation_notice' => [...$cga, ...$notice],
            'quarterly_report_docx' => [...$cga, ...$report],
            default => throw new RuntimeException("Pas de données d'exemple pour {$type}."),
        };
    }

    /**
     * Lignes répétées : deux lignes du reçu ; pour le rapport Word, dirigeants, salariés, un occasionnel, deux mouvements.
     *
     * @return array<string, list<array<string, string>>>
     */
    public function rows(string $type): array
    {
        if ($type === 'receipt') {
            return ['line_label' => [
                ['line_label' => 'Droit d\'adhésion', 'line_amount' => '25 000 FCFA'],
                ['line_label' => 'Honoraires du 01/10/2026 au 31/10/2026', 'line_amount' => '15 000 FCFA'],
            ]];
        }
        if (in_array($type, ['invoice', 'credit_note'], true)) {
            return ['line_label' => [
                ['line_label' => 'Droit d\'adhésion', 'line_quantity' => '1', 'line_unit_amount' => '25 000 FCFA', 'line_tax_rate' => '18 %', 'line_amount' => '25 000 FCFA'],
                ['line_label' => 'Honoraires du 01/10/2026 au 31/10/2026', 'line_quantity' => '1', 'line_unit_amount' => '15 000 FCFA', 'line_tax_rate' => '18 %', 'line_amount' => '15 000 FCFA'],
            ]];
        }
        if ($type !== 'quarterly_report_docx') {
            return [];
        }

        return [
            'director_name' => [
                ['director_index' => '1', 'director_name' => 'EXEMPLE Awa', 'director_profession' => 'Expert-comptable', 'director_function' => 'Gérante', 'director_start_date' => '02/01/2025', 'director_experience' => '15 ans'],
                ['director_index' => '2', 'director_name' => 'EXEMPLE Koné', 'director_profession' => 'Juriste', 'director_function' => 'Associé', 'director_start_date' => '02/01/2025', 'director_experience' => '10 ans'],
            ],
            'employee_name' => [
                ['employee_index' => '1', 'employee_name' => 'EXEMPLE Yao', 'employee_profession' => 'Comptable', 'employee_function' => 'Comptable', 'employee_start_date' => '01/02/2025', 'employee_cnps' => 'D'],
                ['employee_index' => '2', 'employee_name' => 'EXEMPLE Aya', 'employee_profession' => 'Assistante', 'employee_function' => 'Secrétaire', 'employee_start_date' => '01/03/2025', 'employee_cnps' => 'D'],
            ],
            'occasional_name' => [
                ['occasional_index' => '1', 'occasional_name' => 'EXEMPLE Ibrahim', 'occasional_diploma' => 'BTS', 'occasional_function' => 'Stagiaire', 'occasional_since' => '01/09/2026'],
            ],
            'movement_name' => [
                ['movement_name' => 'ENTREPRISE EXEMPLE', 'movement_ncc' => '1111111 B', 'movement_regime' => 'RSI', 'movement_number' => 'EXA-2026-B00001', 'movement_status' => 'Nouvel adhérent'],
                ['movement_name' => 'BOUTIQUE EXEMPLE', 'movement_ncc' => '2222222 C', 'movement_regime' => 'TEE', 'movement_number' => 'EXA-2026-A00007', 'movement_status' => 'Résilié'],
            ],
        ];
    }

    /**
     * Valeurs et lignes du classeur officiel : identité du CGA, personnel, deux adhérents du premier
     * CDI ; les autres blocs restent vides.
     *
     * @return array{values: array<string, string|int>, rows: array<string, array<int, list<array<string, string|int>>>>}
     */
    public function workbook(): array
    {
        $values = $this->values('quarterly_report_docx');
        $blank = array_fill_keys([
            'director_name', 'director_nationality', 'director_profession', 'director_diplomas', 'director_function', 'director_start_date',
            'director_practice_structure', 'director_practice_function', 'director_practice_years',
            'employee_name', 'employee_nationality', 'employee_profession', 'employee_diplomas', 'employee_function', 'employee_start_date',
            'employee_cnps', 'employee_its', 'employee_igr',
            'occasional_name', 'occasional_nationality', 'occasional_profession', 'occasional_diplomas', 'occasional_function',
            'occasional_start_date', 'occasional_duration',
            'member_name', 'member_ncc', 'member_activity', 'member_address', 'membership_number_date', 'member_section_parcel',
            'member_turnover', 'registration_date', 'termination_date_reason', 'synthetic_client_name', 'synthetic_client_ncc',
            'rsi_client_name', 'rsi_client_ncc',
        ], '');

        return [
            'values' => [
                ...$blank,
                ...array_intersect_key($values, array_flip(['cga_name', 'cga_ncc', 'cga_agreement', 'cga_seat', 'cga_location', 'cga_contacts',
                    'regional_directorate', 'cga_tax_service', 'cga_legal_form_capital', 'cga_history', 'cga_previous_year_revenue', 'report_date', 'trainings'])),
                'report_quarter_label' => 'TROISIÈME TRIMESTRE 2026', 'period_start' => '01/07/2026', 'period_end' => '30/09/2026',
                'tax_office_name' => 'EXEMPLE', 'training_beneficiaries' => 'Adhérents des régimes TEE et RME.',
                'res_rooms' => 3, 'res_training_rooms' => 1, 'res_furniture' => 6, 'res_computers' => 5, 'res_software' => 2,
                'res_projectors' => 1, 'res_vehicles' => 0, 'res_other' => 0,
            ],
            'rows' => [
                'Fiche tech CGA' => [
                    39 => [['director_name' => 'EXEMPLE Awa', 'director_nationality' => 'Ivoirienne', 'director_profession' => 'Expert-comptable', 'director_diplomas' => 'DECOFI', 'director_function' => 'Gérante', 'director_start_date' => '02/01/2025', 'director_practice_years' => 15]],
                    48 => [['employee_name' => 'EXEMPLE Yao', 'employee_nationality' => 'Ivoirienne', 'employee_profession' => 'Comptable', 'employee_diplomas' => 'BTS', 'employee_function' => 'Comptable', 'employee_start_date' => '01/02/2025', 'employee_cnps' => 'D']],
                ],
                'Suivi TEE' => [
                    16 => [
                        ['member_name' => 'BOUTIQUE EXEMPLE', 'member_ncc' => '2222222 C', 'member_activity' => 'Commerce', 'member_address' => 'Abidjan, Adjamé', 'membership_number_date' => 'EXA-2026-A00007/02-01-2026', 'member_section_parcel' => '—', 'member_turnover' => 12000000],
                        ['member_name' => 'ATELIER EXEMPLE', 'member_ncc' => '3333333 D', 'member_activity' => 'Couture', 'member_address' => 'Abidjan, Yopougon', 'membership_number_date' => 'EXA-2026-A00008/05-01-2026', 'member_section_parcel' => '—', 'member_turnover' => 8000000],
                    ],
                ],
            ],
        ];
    }

    /**
     * Graphiques du rapport modernisé à éprouver (décision T-21) : répartition par régime (secteurs),
     * adhérents par CDI (barres, deux séries), évolution trimestrielle (courbe).
     *
     * @return array<string, ReportChart>
     */
    public function charts(): array
    {
        return [
            'chart_regimes' => new ReportChart('pie', 'Portefeuille par régime', ['TEE', 'RME', 'RSI'], ['Adhérents' => [120, 45, 12]]),
            'chart_tax_offices' => new ReportChart('column', 'Adhérents par CDI', ['CDI Nord', 'CDI Sud', 'CDI Est'], [
                'Adhérents actifs' => [40, 55, 30],
                'Nouveaux du trimestre' => [5, 8, 2],
            ], 'Nombre d\'adhérents'),
            'chart_trend' => new ReportChart('line', 'Adhérents actifs par trimestre', ['T4 2025', 'T1 2026', 'T2 2026', 'T3 2026'], ['Adhérents actifs' => [140, 150, 165, 177]]),
        ];
    }

    /**
     * Modèle d'essai des graphiques : un titre et, pour chaque graphique, sa variable ${…} suivie de
     * son tableau de données (accessibilité, 04-UX-UI). Le modèle du rapport modernisé suivra ce
     * principe à l'étape du rapport trimestriel.
     */
    public function chartTemplate(string $path): string
    {
        $document = new PhpWord;
        $section = $document->addSection();
        $section->addText('Rapport modernisé — essai des graphiques', ['bold' => true, 'size' => 16, 'color' => '1E6A4E']);

        foreach ($this->charts() as $variable => $chart) {
            $section->addText($chart->title, ['bold' => true, 'size' => 12]);
            $section->addText('${'.$variable.'}');
            $table = $section->addTable(['borderSize' => 4, 'borderColor' => 'E1E6E0', 'cellMargin' => 60]);
            $table->addRow();
            $table->addCell(2400)->addText('');
            foreach (array_keys($chart->series) as $name) {
                $table->addCell(2400)->addText($name, ['bold' => true]);
            }
            foreach ($chart->categories as $index => $category) {
                $table->addRow();
                $table->addCell(2400)->addText($category);
                foreach ($chart->series as $values) {
                    $table->addCell(2400)->addText((string) $values[$index]);
                }
            }
            $section->addTextBreak();
        }

        IOFactory::createWriter($document, 'Word2007')->save($path);

        return $path;
    }

    /**
     * Images de remplacement, créées dans le dossier donné.
     *
     * @return array<string, string> variable => chemin
     */
    public function images(string $directory, string $verificationUrl): array
    {
        $images = [
            'cga_logo' => $this->placeholder($directory.'/logo.png', 'LOGO DU CGA', [40, 90, 160]),
            'cga_signature' => $this->placeholder($directory.'/signature.png', 'SIGNATURE', [255, 255, 255]),
            'cga_stamp' => $this->placeholder($directory.'/cachet.png', 'CACHET', [200, 220, 255]),
        ];

        $qr = (new PngWriter)->write(new QrCode(data: $verificationUrl, size: 240, margin: 8));
        $qr->saveToFile($images['qr_code'] = $directory.'/qr.png');
        (new PngWriter)->write(new QrCode(data: 'FNE-EXEMPLE-0001', size: 240, margin: 8))->saveToFile($images['fne_qr'] = $directory.'/fne-qr.png');

        return $images;
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $background
     */
    private function placeholder(string $path, string $label, array $background): string
    {
        $size = match ($label) {
            'LOGO DU CGA' => ['width' => 300, 'height' => 120],
            default => (array) $this->config->get('documents.images.'.($label === 'SIGNATURE' ? 'cga_signature' : 'cga_stamp')),
        };
        $width = (int) ($size['width'] ?? 120) * 2;
        $height = (int) ($size['height'] ?? 120) * 2;

        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            throw new RuntimeException('Image de remplacement impossible à créer.');
        }
        imagefill($image, 0, 0, (int) imagecolorallocate($image, ...$background));
        $ink = (int) imagecolorallocate($image, 20, 20, 20);
        imagerectangle($image, 1, 1, $width - 2, $height - 2, $ink);
        imagestring($image, 5, 10, (int) ($height / 2) - 8, $label, $ink);
        imagepng($image, $path);

        return $path;
    }
}
