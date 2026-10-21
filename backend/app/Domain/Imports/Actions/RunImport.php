<?php

namespace App\Domain\Imports\Actions;

use App\Domain\Imports\Contracts\FinishesImport;
use App\Domain\Imports\Contracts\RowImporter;
use App\Domain\Imports\Data\RowOutcome;
use App\Domain\Imports\Enums\ImportRowStatus;
use App\Domain\Imports\Enums\ImportStatus;
use App\Domain\Imports\Models\ImportBatch;
use App\Domain\Imports\Models\ImportRow;
use App\Domain\Imports\Support\ImporterRegistry;
use App\Domain\Imports\Support\ImportWorkbook;
use App\Domain\Imports\Support\UnreadableImportFile;
use App\Support\Files\StorageService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Traitement d'un lot (M17) : chaque ligne du fichier est importée dans sa propre transaction et son
 * sort inscrit (import_rows) ; les compteurs avancent ligne après ligne (barre de progression) ; le
 * rapport d'anomalies ligne par ligne est rangé avec le lot (F-IMP-01, RG-IMP-02). Un fichier
 * illisible ou sans les colonnes du modèle met le lot en échec, motif dans le rapport.
 */
class RunImport
{
    public function __construct(
        private readonly ImporterRegistry $importers,
        private readonly ImportWorkbook $workbook,
        private readonly StorageService $storage,
    ) {}

    /**
     * @param  array<string, mixed>  $options  choix faits au dépôt du fichier, pour l'importateur qui termine le lot
     */
    public function execute(ImportBatch $batch, array $options = []): ImportBatch
    {
        $importer = $this->importers->for($batch->type);
        $batch->status = ImportStatus::Processing;
        $batch->save();

        $path = (string) tempnam(sys_get_temp_dir(), 'cga-import-');
        file_put_contents($path, $this->storage->contents($batch->file));

        /** @var list<array{0: int, 1: string, 2: string|null, 3: string}> $lines */
        $lines = [];
        try {
            $positions = $this->workbook->columns($path, $importer);
            $batch->total_rows = $this->workbook->count($path, $positions);
            $batch->save();
            foreach ($this->workbook->rows($path, $positions) as $number => $values) {
                $lines[] = $this->record($batch, $number, $this->importRow($importer, $values));
            }
            $batch->status = ImportStatus::Done;
        } catch (UnreadableImportFile $exception) {
            $lines[] = $this->record($batch, 1, RowOutcome::rejected(null, [$exception->getMessage()]));
            $batch->status = ImportStatus::Failed;
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }

        if ($importer instanceof FinishesImport && $batch->status === ImportStatus::Done) {
            $lines = $this->finish($batch, $importer, $options, $lines);
        }
        $batch->report_file_id = $this->report($batch, $importer, $lines);
        $batch->finished_at = now();
        $batch->save();

        return $batch;
    }

    /**
     * Fin du lot par l'importateur, dans une transaction ; ses remarques s'ajoutent aux lignes du
     * rapport et aux messages des lignes concernées.
     *
     * @param  array<string, mixed>  $options
     * @param  list<array{0: int, 1: string, 2: string|null, 3: string}>  $lines
     * @return list<array{0: int, 1: string, 2: string|null, 3: string}>
     */
    private function finish(ImportBatch $batch, FinishesImport $importer, array $options, array $lines): array
    {
        $notes = DB::transaction(static fn (): array => $importer->finish($batch, $options));
        if ($notes === []) {
            return $lines;
        }

        $rows = $batch->rows()->whereIn('created_entity_id', array_keys($notes))->get()->keyBy('row_number');
        foreach ($rows as $row) {
            $note = $notes[(string) $row->created_entity_id];
            $row->messages = [...$row->messages, $note];
            $row->save();
        }

        return array_map(static function (array $line) use ($rows, $notes): array {
            $row = $rows->get($line[0]);
            if ($row !== null) {
                $line[3] = implode(' ; ', array_filter([$line[3], $notes[(string) $row->created_entity_id]]));
            }

            return $line;
        }, $lines);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function importRow(RowImporter $importer, array $values): RowOutcome
    {
        try {
            return DB::transaction(static fn (): RowOutcome => $importer->import($values));
        } catch (Throwable $exception) {
            // Une ligne en erreur n'arrête pas l'import : l'erreur est signalée, la ligne rejetée.
            report($exception);

            return RowOutcome::rejected(null, [__('imports.row_failed')]);
        }
    }

    /**
     * @return array{0: int, 1: string, 2: string|null, 3: string} ligne du rapport
     */
    private function record(ImportBatch $batch, int $number, RowOutcome $outcome): array
    {
        $row = new ImportRow;
        $row->batch_id = $batch->id;
        $row->row_number = $number;
        $row->status = $outcome->status;
        $row->messages = $outcome->messages;
        $row->created_entity_id = $outcome->createdEntityId;
        $row->save();

        match ($outcome->status) {
            ImportRowStatus::Imported => $batch->imported_rows++,
            ImportRowStatus::Draft => $batch->draft_rows++,
            ImportRowStatus::Rejected => $batch->rejected_rows++,
        };
        // Compteurs d'avancement : le journal garde l'état final du lot, pas chaque ligne lue.
        $batch->saveQuietly();

        return [$number, $outcome->status->label(), $outcome->label, implode(' ; ', $outcome->messages)];
    }

    /**
     * @param  list<array{0: int, 1: string, 2: string|null, 3: string}>  $lines
     */
    private function report(ImportBatch $batch, RowImporter $importer, array $lines): string
    {
        $labelHeader = rtrim($importer->columns()[$importer->labelColumn()]['label'], ' *');
        $contents = $this->workbook->report($labelHeader, $lines, [
            __('imports.report.total') => $batch->total_rows,
            ImportRowStatus::Imported->label() => $batch->imported_rows,
            ImportRowStatus::Draft->label() => $batch->draft_rows,
            ImportRowStatus::Rejected->label() => $batch->rejected_rows,
        ]);
        $name = sprintf('rapport-%s-%s.xlsx', $importer->fileName(), now()->format('Ymd-His'));

        return $this->storage->store($contents, $name, 'imports', $batch->organization_id, $batch->created_by)->id;
    }
}
