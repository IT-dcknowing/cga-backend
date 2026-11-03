# 0009 — Fin du Lot 5 (backend) : reprise d'historique, paramètres du réseau, classeur remplaçable, rapport modernisé

- **Statut** : proposée — interprétations à confirmer par DC-KNOWING et le CNCGA
- **Date** : 03/11/2026
- **Étapes** : 06-IMPLEMENTATION-PLAN, Lot 5, étapes 5.6, 5.6b et 5.13 (backend), 5.10, 5.11
- **Références** : 01-PRD F-IMP-03, RG-DATA-06 et 07, RG-RPT-06 à 12 ; 02-TRD §7.5, §8.3, §8.4 ; décision 0007

## Contexte

Le PRD décrit la reprise d'historique (F-IMP-03) en une phrase : « CA par année, paiements d'impôts par nature et par période ». Les choix ci-dessous fixent le format du fichier, le rattachement aux échéances et le traitement des doublons ; ils sont isolés dans les importeurs et paramétrables.

## Décisions

### 5.6 — Reprise d'historique (F-IMP-03)

1. **Deux fichiers modèles**, déposés par les agents qui suivent les obligations (droit `members.history.import` : gérant, expert-comptable, comptables, juristes, fiscalistes — d'après la ligne « Mettre à jour les obligations d'un adhérent » de la matrice PRD §3.3), traités en file « imports » comme les autres imports, avec rapport ligne par ligne.
2. **Dossier** désigné par son n° CGA Connect (adhésion ou client), son ancien n° repris ou son NCC, cherché dans le centre seulement ; une désignation qui vise plusieurs dossiers est rejetée.
3. **Chiffre d'affaires** : année (à partir de `imports.history.first_year`, jusqu'à l'année en cours ; l'année suivante pour un prévisionnel), montant, réalisé (défaut) ou prévisionnel. Une valeur déjà saisie ou importée est remplacée (avant / après au journal) ; une valeur Compta Flow n'est jamais remplacée (RG-DATA-06) : la ligne est rejetée.
4. **Paiements d'impôts** : nature (code ou libellé), période au format des échéances (`2025`, `2025-S1`, `2025-T3`, `2025-03` ou `03/2025`), montant payé, date, montant dû et référence facultatifs. Le paiement, confirmé d'office (source `import`), est rattaché à l'échéance de même nature et de même période, sinon à celle dont l'échéance tombe dans la période ; à défaut, une **échéance d'historique** est créée (échéance réputée à la fin de la période, montant dû déclaré ou, à défaut, montant payé). Il ne dépasse jamais le reste dû.
5. **Doublons** : un paiement ne s'importe qu'une fois — par sa référence, ou à défaut par l'empreinte de la ligne (dossier, nature, période, date, montant). Une échéance déjà réglée par Compta Flow n'accepte pas d'import.

### 5.6b et 5.13 — Paramètres du réseau et de la plateforme

6. **Une API de paramètres par espace**, sur le registre `config/cga.php` : `/network/settings` (CNCGA, droit `network_settings.manage` et droit propre de chaque clé) pour les clés de niveau réseau ; `/platform/settings` (administrateur, `platform.settings.manage`) pour les clés de niveau plateforme et réseau (03-APPFLOW §6). Lecture par groupe (`report`, `dues`, `closing`…), modification contrôlée par les règles du registre, retour à la valeur par défaut. Les valeurs propres d'un CGA ne passent pas par ici.
7. **Règle « augmentée » (RG-RPT-08)**, décalages des relances (RG-RPT-06), échéance réglementaire (RG-RPT-07), export du classeur (RG-RPT-10) : modifiables sans développement ; chaque modification est tracée avec l'avant et l'après ; une version de rapport déjà générée garde la règle de sa génération (instantané, décision 0007).
8. Libellés et aides des clés dans `lang/fr/settings.php`.

### 5.10 — Classeur officiel remplaçable (RG-RPT-10)

9. Le classeur provisoire (maquette nettoyée) est la version 1 du modèle `quarterly_report_xlsx`. La version officielle du SP-CGA se dépose par l'écran des modèles (3.12) puis s'active ; les générations suivantes l'utilisent sans développement, les versions déjà produites gardent la leur.
10. **Contrôle de structure au dépôt**, en plus du contrôle des variables : nombre de feuilles (`reports.workbook.sheet_count`, 14), feuilles nommées attendues par le remplissage (page de garde, fiche technique, RESUME, feuilles des blocs), dans chaque feuille de bloc une ligne `${tax_office_name}` suivie d'une ligne TOTAL, et une ligne TOTAL après chaque tableau de la feuille RESUME. Chaque écart est un motif de refus distinct (`lang/fr/report.php`, `workbook.*`) ; le contrôle est porté par le type de document (`documents.templates.*.structure`), donc extensible aux autres modèles.

## Preuve

`tests/Feature/Imports/HistoryImportTest.php` (F-IMP-03, RG-DATA-06, RG-RPT-12), `tests/Feature/Platform/SettingsApiTest.php` (RG-RPT-06 à 08, RG-RPT-10), `tests/Feature/Reports/WorkbookTemplateTest.php` (RG-RPT-10).

## Points ouverts

- Format du fichier d'historique à valider avec un vrai fichier DC-KNOWING anonymisé.
- Classeur officiel du SP-CGA à déposer dès réception ; si sa mise en page diffère (feuilles renommées, tableaux déplacés), adapter `reports.workbook` (configuration, sans code).
