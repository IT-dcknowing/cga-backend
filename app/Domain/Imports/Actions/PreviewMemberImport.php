<?php

namespace App\Domain\Imports\Actions;

use App\Domain\Imports\Data\MemberImportLine;
use App\Domain\Imports\Enums\ImportRowStatus;
use App\Domain\Imports\Importers\MemberRowImporter;
use App\Domain\Imports\Support\ImportWorkbook;
use App\Domain\Imports\Support\MemberImportStart;
use App\Domain\Imports\Support\UnreadableImportFile;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Support\MemberNumbering;
use App\Support\Http\Exceptions\BusinessRuleException;
use App\Support\Numbering\SequenceService;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * « Analyser » un portefeuille avant de l'importer (F-IMP-02) : sans rien écrire, le sort de chaque
 * ligne (importable, brouillon avec ce qui manque, rejetée avec ses motifs, RG-IMP-02), un NCC répété
 * dans le fichier étant rejeté, et pour chaque dossier actif l'ancien n° en regard du n° CGA Connect
 * qu'il recevra, dans l'ordre chronologique des adhésions à partir du numéro de départ (RG-IMP-01).
 */
class PreviewMemberImport
{
    public function __construct(
        private readonly MemberRowImporter $importer,
        private readonly ImportWorkbook $workbook,
        private readonly MemberImportStart $start,
        private readonly SequenceService $sequences,
        private readonly MemberNumbering $numbering,
    ) {}

    /**
     * @return array{total: int, importable: int, drafts: int, rejected: int, proposed_start_number: int, start_number: int, rows: list<array<string, mixed>>}
     *
     * @throws BusinessRuleException abbreviation_missing
     * @throws ValidationException numéro de départ déjà attribué, fichier illisible
     */
    public function execute(string $organizationId, UploadedFile $file, ?int $startNumber): array
    {
        ['start' => $start, 'abbreviation' => $abbreviation] = $this->start->check($organizationId, $startNumber);

        /** @var array<int, MemberImportLine> $lines */
        $lines = [];
        $seen = [];
        try {
            $path = (string) $file->getRealPath();
            foreach ($this->workbook->rows($path, $this->workbook->columns($path, $this->importer)) as $number => $values) {
                $line = $this->importer->analyze($values);
                if ($line->status !== ImportRowStatus::Rejected && $line->ncc !== null && isset($seen[$line->ncc])) {
                    $line = $line->rejectedFor(__('imports.members.duplicate_in_file', ['ncc' => $line->ncc, 'row' => $seen[$line->ncc]]));
                }
                if ($line->status !== ImportRowStatus::Rejected && $line->ncc !== null) {
                    $seen[$line->ncc] ??= $number;
                }
                $lines[$number] = $line;
            }
        } catch (UnreadableImportFile $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        $numbers = $this->numbers($organizationId, $lines, $start, $abbreviation);
        $count = static fn (ImportRowStatus $status): int => count(array_filter($lines, static fn (MemberImportLine $line): bool => $line->status === $status));
        $rows = [];
        foreach ($lines as $number => $line) {
            $rows[] = [
                'row' => $number,
                'label' => $line->label,
                'legacy_number' => $line->legacyNumber,
                'portfolio' => $line->portfolio?->value,
                'status' => $line->status->value,
                'status_label' => $line->status->label(),
                'messages' => $line->messages,
                'number' => $numbers[$number] ?? null,
            ];
        }

        return [
            'total' => count($lines),
            'importable' => $count(ImportRowStatus::Imported),
            'drafts' => $count(ImportRowStatus::Draft),
            'rejected' => $count(ImportRowStatus::Rejected),
            'proposed_start_number' => $this->start->proposed($organizationId),
            'start_number' => $start,
            'rows' => $rows,
        ];
    }

    /**
     * Numéros que recevront les dossiers actifs, dans l'ordre chronologique des adhésions puis des lignes.
     *
     * @param  array<int, MemberImportLine>  $lines
     * @return array<int, string> numéro par ligne
     */
    private function numbers(string $organizationId, array $lines, int $start, string $abbreviation): array
    {
        $active = array_filter($lines, static fn (MemberImportLine $line): bool => $line->active());
        uksort($active, static fn (int $a, int $b): int => [$active[$a]->adhesionDate?->toDateString() ?? '9999-12-31', $a] <=> [$active[$b]->adhesionDate?->toDateString() ?? '9999-12-31', $b]);

        $membership = $start;
        $client = $this->sequences->current($organizationId, MemberNumbering::CLIENT) + 1;
        $numbers = [];
        foreach ($active as $number => $line) {
            $numbers[$number] = $line->portfolio === Portfolio::Cga
                ? $this->numbering->membershipNumber($membership++, $line->adhesionDate?->year, $abbreviation)
                : $this->numbering->clientNumber($client++, $abbreviation);
        }

        return $numbers;
    }
}
