<?php

namespace Tools\Coverage;

use RuntimeException;
use SimpleXMLElement;

/**
 * Seuils de couverture que Pest ne contrôle pas seul (02-TRD §13, 06-IMPLEMENTATION-PLAN §0bis) :
 * Actions et Policies ensemble, et chaque fichier créé ou modifié par la demande de fusion.
 * Le seuil global (--min de Pest) reste contrôlé par Pest.
 */
final class CoverageGate
{
    private const CRITICAL_PATH = '#^app/Domain/[^/]+/(Actions|Policies)/#';

    /**
     * @var array<string, array{covered: int, total: int}>
     */
    private array $files;

    public function __construct(string $cloverXml, private readonly string $basePath)
    {
        $this->files = $this->parse($cloverXml);
    }

    /**
     * @param  list<string>  $changedFiles  chemins relatifs au dossier backend/
     * @return list<string> les manquements, vide si tout est bon
     */
    public function failures(float $criticalMinimum, float $changedMinimum, array $changedFiles): array
    {
        $failures = [];

        $critical = array_filter($this->files, fn (string $path): bool => (bool) preg_match(self::CRITICAL_PATH, $path), ARRAY_FILTER_USE_KEY);
        $criticalPercent = $this->percent($critical);
        if ($criticalPercent !== null && $criticalPercent < $criticalMinimum) {
            $failures[] = sprintf('Actions et Policies : %.1f %% des lignes couvertes, minimum %.0f %%.', $criticalPercent, $criticalMinimum);
        }

        foreach ($changedFiles as $changedFile) {
            $path = $this->normalize($changedFile);
            if (! isset($this->files[$path])) {
                continue;
            }

            $percent = $this->percent([$path => $this->files[$path]]);
            if ($percent !== null && $percent < $changedMinimum) {
                $failures[] = sprintf('%s : %.1f %% des lignes couvertes, minimum %.0f %% pour un fichier modifié.', $path, $percent, $changedMinimum);
            }
        }

        return $failures;
    }

    /**
     * @param  array<string, array{covered: int, total: int}>  $files
     */
    private function percent(array $files): ?float
    {
        $total = array_sum(array_column($files, 'total'));

        return $total === 0 ? null : array_sum(array_column($files, 'covered')) / $total * 100;
    }

    /**
     * @return array<string, array{covered: int, total: int}>
     */
    private function parse(string $cloverXml): array
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($cloverXml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $xml instanceof SimpleXMLElement) {
            throw new RuntimeException('Rapport de couverture Clover illisible.');
        }

        $files = [];
        foreach ($xml->xpath('//file') ?: [] as $file) {
            $metrics = $file->metrics;
            $files[$this->normalize((string) $file['name'])] = [
                'covered' => (int) $metrics['coveredstatements'],
                'total' => (int) $metrics['statements'],
            ];
        }

        return $files;
    }

    private function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $base = rtrim(str_replace('\\', '/', $this->basePath), '/').'/';

        if (str_starts_with(strtolower($path), strtolower($base))) {
            $path = substr($path, strlen($base));
        }

        return preg_replace('#^backend/#', '', ltrim($path, '/')) ?? $path;
    }
}
