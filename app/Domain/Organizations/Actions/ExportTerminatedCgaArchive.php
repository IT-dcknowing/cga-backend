<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Documents\Models\File;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Members\Models\Member;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Payments\Models\MemberPayment;
use App\Domain\Users\Models\User;
use App\Support\Files\StorageService;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use ZipArchive;

/**
 * Produit l'archive complète d'un CGA résilié (RG-ORG-07) : listes CSV (adhérents, obligations,
 * paiements) et copies de ses documents PDF. Le fichier ZIP est rangé par StorageService.
 */
class ExportTerminatedCgaArchive
{
    public function __construct(
        private readonly StorageService $storage,
    ) {}

    public function execute(Organization $organization, ?User $actor = null): File
    {
        return Tenancy::bypass(function () use ($organization, $actor): File {
            return DB::transaction(function () use ($organization, $actor): File {
            $tempZip = tempnam(sys_get_temp_dir(), 'cga_term_');
            $zip = new ZipArchive;
            $zip->open($tempZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);

            // 1. Liste des adhérents
            $membersCsv = fopen('php://temp', 'r+');
            fprintf($membersCsv, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($membersCsv, ['Numéro', 'Ancien numéro', 'NCC', 'Dénomination', 'Régime', 'Portefeuille', 'Statut', 'Date adhésion'], ';');
            $members = Member::query()->where('organization_id', $organization->id)->get();
            foreach ($members as $m) {
                fputcsv($membersCsv, [
                    $m->membership_number ?? '',
                    $m->legacy_number ?? '',
                    $m->ncc ?? '',
                    $m->company_name,
                    $m->regime?->value ?? '',
                    $m->portfolio?->value ?? '',
                    $m->status?->value ?? '',
                    $m->adhesion_date?->format('d/m/Y') ?? '',
                ], ';');
            }
            rewind($membersCsv);
            $zip->addFromString('adherents.csv', stream_get_contents($membersCsv));
            fclose($membersCsv);

            // 2. Liste des paiements
            $paymentsCsv = fopen('php://temp', 'r+');
            fprintf($paymentsCsv, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($paymentsCsv, ['ID', 'Adhérent', 'Montant FCFA', 'Date', 'Mode', 'Statut', 'Référence'], ';');
            $payments = MemberPayment::query()->where('organization_id', $organization->id)->with('member')->get();
            foreach ($payments as $p) {
                fputcsv($paymentsCsv, [
                    $p->id,
                    $p->member?->company_name ?? '',
                    $p->amount,
                    $p->paid_on?->format('d/m/Y') ?? '',
                    $p->method?->value ?? '',
                    $p->status?->value ?? '',
                    $p->reference ?? '',
                ], ';');
            }
            rewind($paymentsCsv);
            $zip->addFromString('paiements.csv', stream_get_contents($paymentsCsv));
            fclose($paymentsCsv);

            // 3. Liste des obligations
            $obligationsCsv = fopen('php://temp', 'r+');
            fprintf($obligationsCsv, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($obligationsCsv, ['ID', 'Adhérent', 'Obligation', 'Échéance', 'Statut'], ';');
            $instances = ObligationInstance::query()->where('organization_id', $organization->id)->with(['member'])->get();
            foreach ($instances as $inst) {
                fputcsv($obligationsCsv, [
                    $inst->id,
                    $inst->member?->company_name ?? '',
                    $inst->label ?? '',
                    $inst->due_date?->format('d/m/Y') ?? '',
                    $inst->status?->value ?? '',
                ], ';');
            }
            rewind($obligationsCsv);
            $zip->addFromString('obligations.csv', stream_get_contents($obligationsCsv));
            fclose($obligationsCsv);

            // 4. Documents PDF du centre
            $documents = GeneratedDocument::query()
                ->where('organization_id', $organization->id)
                ->whereNotNull('pdf_file_id')
                ->with('pdf')
                ->get();

            foreach ($documents as $doc) {
                if ($doc->pdf) {
                    try {
                        $contents = $this->storage->contents($doc->pdf);
                        $filename = sprintf('documents/%s_%s', $doc->id, $doc->pdf->original_name);
                        $zip->addFromString($filename, $contents);
                    } catch (\Throwable) {
                        // Fichier manquant ou illisible ignoré
                    }
                }
            }

            $zip->close();

            $zipContents = file_get_contents($tempZip);
            @unlink($tempZip);

            $archiveName = sprintf(
                'archive-resiliation-%s-%s.zip',
                $organization->sigle ?? 'cga',
                CarbonImmutable::now()->format('Ymd-His')
            );

            return $this->storage->store(
                contents: $zipContents ?: '',
                originalName: $archiveName,
                type: 'exports',
                organizationId: $organization->id,
                uploaderId: $actor?->id
            );
        });
        }, 'Export complet d archive pour CGA resilie RG-ORG-07');
    }
}
