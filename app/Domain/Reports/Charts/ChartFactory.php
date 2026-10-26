<?php

namespace App\Domain\Reports\Charts;

use Illuminate\Contracts\Config\Repository;
use PhpOffice\PhpWord\Element\Chart;

/**
 * Graphique natif PhpWord d'un ReportChart (décision T-21), à la palette du rapport modernisé
 * (04-UX-UI : barres primary, série secondaire, alerte accent ; valeurs en étiquette ; légende sous
 * le graphique), déclarée une seule fois dans config/documents.php. Un secteur montre ses
 * pourcentages, les autres types leurs valeurs.
 */
class ChartFactory
{
    public function __construct(private readonly Repository $config) {}

    public function make(ReportChart $chart): Chart
    {
        $names = array_keys($chart->series);
        $values = array_values($chart->series);

        $element = new Chart($chart->type, $chart->categories, $values[0], $this->style($chart), $this->escape($names[0]));
        foreach (array_slice($values, 1, null, true) as $index => $series) {
            $element->addSeries($chart->categories, $series, $this->escape($names[$index]));
        }

        return $element;
    }

    /**
     * @return array<string, mixed>
     */
    private function style(ReportChart $chart): array
    {
        $palette = array_values((array) $this->config->get('documents.charts.palette'));
        $pie = $chart->type === 'pie';

        return [
            'width' => $this->config->integer('documents.charts.width'),
            'height' => $this->config->integer('documents.charts.height'),
            'title' => $this->escape($chart->title),
            'showLegend' => $pie || count($chart->series) > 1,
            'legendPosition' => $this->config->string('documents.charts.legend_position'),
            'showAxisLabels' => ! $pie,
            'valueAxisTitle' => $chart->valueAxisTitle === null ? null : $this->escape($chart->valueAxisTitle),
            'colors' => $pie ? $palette : $this->seriesColors($palette, count($chart->series), count($chart->categories)),
            'dataLabelOptions' => [
                'showVal' => ! $pie, 'showPercent' => $pie, 'showCatName' => false,
                'showSerName' => false, 'showLegendKey' => false, 'showLeaderLines' => $pie,
            ],
        ];
    }

    /**
     * Couleur de la série en XML (spPr), que PhpWord n'écrit pas : la légende et le trait d'une courbe
     * prennent ainsi la couleur de la palette. Les secteurs gardent leurs couleurs par point.
     */
    public function colorSeries(string $chartXml): string
    {
        if (str_contains($chartXml, '<c:pieChart>')) {
            return $chartXml;
        }

        $palette = array_values((array) $this->config->get('documents.charts.palette'));
        $line = str_contains($chartXml, '<c:lineChart>');
        $index = 0;

        // Dans chaque série seulement : le titre et les titres d'axes ont aussi un c:tx.
        return (string) preg_replace_callback('#<c:ser>.*?</c:ser>#s', function (array $series) use ($palette, $line, &$index): string {
            $fill = '<a:solidFill><a:srgbClr val="'.$palette[$index++ % count($palette)].'"/></a:solidFill>';
            $properties = '<c:spPr>'.($line ? '<a:ln w="28575" cap="rnd">'.$fill.'</a:ln>' : $fill).'</c:spPr>';

            return (string) preg_replace('#</c:tx>#', '</c:tx>'.$properties, $series[0], 1);
        }, $chartXml);
    }

    /**
     * PhpWord colore point par point, à la suite, toutes séries confondues : chaque série reçoit
     * ainsi une couleur de la palette, répétée pour chacun de ses points.
     *
     * @param  list<string>  $palette
     * @return list<string>
     */
    private function seriesColors(array $palette, int $series, int $points): array
    {
        $colors = [];
        for ($i = 0; $i < $series; $i++) {
            array_push($colors, ...array_fill(0, $points, $palette[$i % count($palette)]));
        }

        return $colors;
    }

    /**
     * Titres et noms de séries : PhpWord les écrit tels quels dans le XML du graphique.
     */
    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
