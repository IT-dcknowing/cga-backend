# 0002 — Graphiques du rapport modernisé (T-21)

- **Statut** : proposée — en attente du contrôle visuel de la QA
- **Date** : 20/10/2026
- **Étape** : 06-IMPLEMENTATION-PLAN, Lot 0, étape 0.12
- **Références** : 02-TRD §8.4 et T-21, 04-UX-UI (Charts, rapport modernisé), RG-RPT-09 à 12

## Contexte

Le rapport trimestriel modernisé est un DOCX rempli par PhpWord, puis converti en PDF par LibreOffice (chaîne de la décision 0001). Le TRD prévoit des graphiques natifs PhpWord (secteurs, barres, courbes) et, si LibreOffice ne les rend pas fidèlement, un repli en images PNG produites côté serveur.

## Décision

**Graphiques natifs PhpWord**, insérés à la place d'une variable `${chart_…}` du modèle et convertis par LibreOffice. Le repli PNG n'est pas développé ; il ne le sera que si la QA constate un rendu infidèle.

## Ce qui a été fait

- `ReportChart` décrit un graphique (type `pie`, `column`, `bar` ou `line`, titre, catégories, séries, titre de l'axe des valeurs) ; il refuse un graphique mal formé (série incomplète, secteurs à plusieurs séries).
- `ChartFactory` en fait un graphique PhpWord à la palette de la charte, déclarée une seule fois dans `backend/config/documents.php` (`1E6A4E`, `4E9273`, `E5732A`, `2B4C7E`…) : légende sous le graphique, valeurs en étiquettes (pourcentages pour les secteurs), titre et axes.
- `DocxTemplateRenderer` insère les graphiques dans le modèle, à la place du paragraphe de leur variable (`setChart`).
- Deux limites de PhpWord 1.4 sont compensées :
  - il ne colore que les points, pas les séries : la couleur de chaque série est ajoutée au XML du graphique, pour que la légende et le trait des courbes suivent la palette ;
  - il écrit le titre et le nom des séries sans échappement XML : `ChartFactory` les échappe (un nom de CDI contenant « & » ne casse pas le document).
- Chaque graphique est suivi de son tableau de données dans le modèle d'essai (accessibilité, 04-UX-UI) ; le modèle du rapport modernisé suivra ce principe.

## Preuves sur le poste de développement

| Contrôle | Résultat |
|---|---|
| Secteurs « Portefeuille par régime » | Rendu par LibreOffice avec les pourcentages et la légende |
| Barres « Adhérents par CDI » (2 séries) | Barres, valeurs, titre d'axe et légende aux couleurs de la palette |
| Courbe « Adhérents actifs par trimestre » | Tracée à la couleur de la série |
| Conversion PDF | 2 pages, environ 7 s sur ce poste |

## Contrôle visuel attendu de la QA

```bash
cd backend
php artisan documents:samples            # report_charts.docx et report_charts.pdf
```

- [ ] les trois graphiques s'affichent dans Word, dans LibreOffice et dans le PDF ;
- [ ] couleurs de la charte, légende sous le graphique, valeurs ou pourcentages lisibles ;
- [ ] titres, axes et libellés en français, sans caractère abîmé.

Tout écart rouvre le repli PNG.

## Conséquences

- Le service de calcul du rapport (`QuarterlyReportCalculator`, lot 3) fournira des `ReportChart` ; l'écran et le document utilisent la même palette.
- Aucune dépendance supplémentaire : PhpWord et LibreOffice sont déjà sur le serveur.
