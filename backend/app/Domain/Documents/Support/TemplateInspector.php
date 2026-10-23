<?php

namespace App\Domain\Documents\Support;

use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Templates\DocxTemplateRenderer;
use Illuminate\Contracts\Config\Repository;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use RuntimeException;
use ZipArchive;

/**
 * Contrôle des variables d'un modèle de document (F-ADM-02, RG-DATA-03) : variables ${…} trouvées
 * dans le fichier (textes, images, lignes répétées ; cellules d'un classeur), comparées à celles que la
 * plateforme remplit pour ce type de document (config/documents.php) — manquantes (une mention ne
 * serait jamais remplie) ou inconnues (elle resterait « ${…} » dans le document). Une version n'est
 * activable que sans écart. La structure du classeur officiel (feuilles, cellules) est contrôlée à
 * part (étape 5.10).
 */
class TemplateInspector
{
    private const DOCUMENT_BODY = 'word/document.xml';

    public function __construct(
        private readonly Repository $config,
        private readonly DocxTemplateRenderer $renderer,
    ) {}

    /**
     * Format du fichier attendu pour ce type (docx ou xlsx), null si la plateforme ne produit pas ce
     * type à partir d'un modèle.
     */
    public function format(DocumentType $type): ?string
    {
        $format = $this->config->get("documents.templates.{$type->value}.format");

        return is_string($format) ? $format : null;
    }

    /**
     * Variables que la plateforme remplit pour ce type : textes, images, lignes répétées.
     *
     * @param  array<string, mixed>  $definition  entrée de documents.templates
     * @return list<string>
     */
    public static function expected(array $definition): array
    {
        $rows = array_values((array) ($definition['rows'] ?? []));

        return self::sorted([
            ...(array) ($definition['variables'] ?? []),
            ...(array) ($definition['images'] ?? []),
            ...(array) ($definition['framed_images'] ?? []),
            ...array_merge([], ...array_map(static fn (mixed $row): array => (array) $row, $rows)),
        ]);
    }

    /**
     * Variables présentes dans le fichier.
     *
     * @return list<string>
     *
     * @throws RuntimeException fichier Word sans corps de document
     */
    public function variables(DocumentType $type, string $path): array
    {
        if ($this->format($type) === 'xlsx') {
            return $this->workbook($path);
        }

        $zip = new ZipArchive;
        $body = $zip->open($path) === true && $zip->locateName(self::DOCUMENT_BODY) !== false;
        $zip->close();
        if (! $body) {
            throw new RuntimeException('Modèle Word sans corps de document ('.self::DOCUMENT_BODY.').');
        }

        return self::sorted([...$this->renderer->variables($path), ...array_keys($this->renderer->framedImages($path))]);
    }

    /**
     * Écarts entre les variables d'une version et celles que la plateforme remplit.
     *
     * @param  list<string>  $variables
     * @return array{missing: list<string>, unknown: list<string>, valid: bool}
     */
    public function check(DocumentType $type, array $variables): array
    {
        $expected = self::expected((array) $this->config->get("documents.templates.{$type->value}", []));
        $missing = array_values(array_diff($expected, $variables));
        $unknown = array_values(array_diff($variables, $expected));

        return ['missing' => $missing, 'unknown' => $unknown, 'valid' => $missing === [] && $unknown === []];
    }

    /**
     * @return list<string>
     */
    private function workbook(string $path): array
    {
        $variables = [];
        foreach (IOFactory::load($path)->getAllSheets() as $sheet) {
            foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
                $value = $sheet->getCell($coordinate)->getValue();
                $text = $value instanceof RichText ? $value->getPlainText() : (is_string($value) ? $value : '');
                preg_match_all('/\$\{([a-z0-9_]+)\}/', $text, $matches);
                array_push($variables, ...$matches[1]);
            }
        }

        return self::sorted($variables);
    }

    /**
     * @param  array<int, mixed>  $variables
     * @return list<string>
     */
    private static function sorted(array $variables): array
    {
        $variables = array_values(array_unique(array_map('strval', $variables)));
        sort($variables);

        return $variables;
    }
}
