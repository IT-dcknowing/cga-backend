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

## Preuve

`tests/Feature/Imports/HistoryImportTest.php` (F-IMP-03, RG-DATA-06, RG-RPT-12).

## Points ouverts

- Format du fichier d'historique à valider avec un vrai fichier DC-KNOWING anonymisé.
