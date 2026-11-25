<?php

namespace App\Domain\Imports\Support;

use App\Domain\Imports\Contracts\SpreadsheetLayout;
use Generator;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Common\Exception\IOException;
use OpenSpout\Reader\XLSX\Options as ReaderOptions;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Classeurs Excel des imports (M17), lus et écrits en flux par OpenSpout (02-TRD §4.2) : modèle à
 * télécharger (feuille « Données » et feuille « Aide »), lecture de la première feuille d'un fichier
 * déposé, colonnes reconnues par leur en-tête (casse, accents et ponctuation ignorés).
 */
final class ImportWorkbook
{
    public const DATA_SHEET = 'Données';

    public const HELP_SHEET = 'Aide';

    /**
     * Modèle à remplir : en-têtes en gras, une feuille d'aide colonne par colonne.
     */
    public function template(SpreadsheetLayout $importer): string
    {
        $columns = $importer->columns();

        return $this->write(function (Writer $writer) use ($importer, $columns): void {
            $writer->getCurrentSheet()->setName(self::DATA_SHEET);
            $writer->getCurrentSheet()->setColumnWidthForRange(24, 1, count($columns));
            $writer->addRow(Row::fromValuesWithStyle(array_column($columns, 'label'), new Style(fontBold: true, backgroundColor: 'D9E2F3')));

            $help = $writer->addNewSheetAndMakeItCurrent()->setName(self::HELP_SHEET);
            $help->setColumnWidth(28, 1);
            $help->setColumnWidth(14, 2);
            $help->setColumnWidth(90, 3);
            $writer->addRow(Row::fromValuesWithStyle([__('imports.template.column'), __('imports.template.required'), __('imports.template.help')], new Style(fontBold: true)));
            foreach ($columns as $key => $column) {
                $writer->addRow(Row::fromValues([
                    $column['label'],
                    in_array($key, $importer->requiredColumns(), true) ? __('imports.template.yes') : __('imports.template.no'),
                    $column['help'],
                ]));
            }
        });
    }

    /**
     * Position de chaque colonne du modèle, lue sur la première ligne non vide de la première feuille.
     * Le classeur est refermé avant toute erreur (un fichier ouvert ne peut pas être effacé sous
     * Windows).
     *
     * @return array<string, int>
     *
     * @throws UnreadableImportFile fichier illisible ou vide, en-tête sans les colonnes obligatoires
     */
    public function columns(string $path, SpreadsheetLayout $importer): array
    {
        $reader = $this->open($path);
        $headers = null;

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    if (! $row->isEmpty()) {
                        $headers = $row->toArray();

                        break;
                    }
                }

                break;
            }
        } finally {
            // Les objets du lecteur se référencent entre eux : lecture interrompue, ils ne sont libérés,
            // et le fichier refermé, qu'au passage du ramasse-miettes.
            unset($sheet, $row);
            $reader->close();
            unset($reader);
            gc_collect_cycles();
        }

        return $headers === null
            ? throw new UnreadableImportFile(__('imports.file.empty'))
            : $this->positions($headers, $importer);
    }

    /**
     * Lignes non vides sous l'en-tête de la première feuille, par numéro de ligne Excel : valeurs par
     * clé de colonne.
     *
     * @param  array<string, int>  $positions  colonnes lues par columns()
     * @return Generator<int, array<string, mixed>>
     */
    public function rows(string $path, array $positions): Generator
    {
        $reader = $this->open($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $header = true;
                foreach ($sheet->getRowIterator() as $number => $row) {
                    if ($row->isEmpty()) {
                        continue;
                    }
                    if ($header) {
                        $header = false;

                        continue;
                    }

                    $cells = $row->toArray();
                    $values = [];
                    foreach ($positions as $key => $position) {
                        $values[$key] = $this->value($cells[$position] ?? null);
                    }
                    if (array_filter($values, static fn (mixed $value): bool => $value !== null) !== []) {
                        yield $number => $values;
                    }
                }

                return;
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * Nombre de lignes à traiter (barre de progression).
     *
     * @param  array<string, int>  $positions
     */
    public function count(string $path, array $positions): int
    {
        return iterator_count($this->rows($path, $positions));
    }

    /**
     * Rapport d'import : une ligne par ligne lue, puis la synthèse.
     *
     * @param  iterable<array{0: int, 1: string, 2: string|null, 3: string}>  $lines  [n° de ligne, statut, libellé, messages]
     * @param  array<string, int>  $summary  libellé => nombre
     */
    public function report(string $labelHeader, iterable $lines, array $summary): string
    {
        return $this->write(function (Writer $writer) use ($labelHeader, $lines, $summary): void {
            $sheet = $writer->getCurrentSheet()->setName(__('imports.report.sheet'));
            $sheet->setColumnWidth(10, 1);
            $sheet->setColumnWidth(16, 2);
            $sheet->setColumnWidth(40, 3);
            $sheet->setColumnWidth(100, 4);
            $writer->addRow(Row::fromValuesWithStyle([__('imports.report.row'), __('imports.report.status'), $labelHeader, __('imports.report.messages')], new Style(fontBold: true)));
            foreach ($lines as $line) {
                $writer->addRow(Row::fromValues($line));
            }

            $writer->addNewSheetAndMakeItCurrent()->setName(__('imports.report.summary'));
            foreach ($summary as $label => $count) {
                $writer->addRow(Row::fromValues([$label, $count]));
            }
        });
    }

    /**
     * Clé normalisée d'un en-tête : minuscules sans accents ni ponctuation.
     */
    public static function normalize(string $text): string
    {
        return (string) Str::of($text)->ascii('fr')->lower()->replaceMatches('/[^a-z0-9]+/', '');
    }

    /**
     * @param  callable(Writer): void  $fill
     */
    private function write(callable $fill): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cga-xlsx-');
        $writer = new Writer;

        try {
            $writer->openToFile($path);
            $fill($writer);
            $writer->close();

            return (string) file_get_contents($path);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * @throws UnreadableImportFile
     */
    private function open(string $path): Reader
    {
        // Lignes vides gardées : les clés des lignes sont alors leurs numéros Excel, repris au rapport.
        $reader = new Reader(new ReaderOptions(SHOULD_PRESERVE_EMPTY_ROWS: true));

        try {
            $reader->open($path);
        } catch (IOException) {
            throw new UnreadableImportFile(__('imports.file.unreadable'));
        }

        return $reader;
    }

    /**
     * Position de chaque colonne du modèle d'après la ligne d'en-tête.
     *
     * @param  array<int, mixed>  $headers
     * @return array<string, int>
     *
     * @throws UnreadableImportFile colonnes obligatoires absentes
     */
    private function positions(array $headers, SpreadsheetLayout $importer): array
    {
        $found = [];
        foreach ($headers as $position => $header) {
            $normalized = is_scalar($header) ? self::normalize((string) $header) : '';
            foreach ($importer->columns() as $key => $column) {
                $names = array_map(self::normalize(...), [$key, $column['label'], ...$column['aliases']]);
                if ($normalized !== '' && ! isset($found[$key]) && in_array($normalized, $names, true)) {
                    $found[$key] = $position;
                }
            }
        }

        $missing = array_diff($importer->requiredColumns(), array_keys($found));
        if ($missing !== []) {
            $labels = array_map(static fn (string $key): string => $importer->columns()[$key]['label'], array_values($missing));

            throw new UnreadableImportFile(__('imports.file.missing_columns', ['columns' => implode(', ', $labels)]));
        }

        return $found;
    }

    private function value(mixed $cell): mixed
    {
        if (is_string($cell)) {
            $cell = trim($cell);

            return $cell === '' ? null : $cell;
        }

        return $cell;
    }
}
