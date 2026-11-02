# 0007 — Rapport trimestriel : états arrêtés, complétude, versions, transmission

- **Statut** : proposée — interprétations à confirmer par DC-KNOWING (règles de calcul) et la QA (classeur)
- **Date** : 02/11/2026
- **Étapes** : 06-IMPLEMENTATION-PLAN, Lot 5, étapes 5.1 à 5.5 (et 5.6b pour l'instantané de la règle)
- **Références** : 01-PRD M09 (F-RPT-01 à 07, RG-RPT-01 à 08), F-ORG-07, RG-OBL-12 ; 02-TRD §8.3, §8.4, §11 ; 05-BACKEND-SCHEMA §9, §16

## Contexte

Le PRD fixe les feuilles du rapport et leurs sources, mais pas certaines règles de détail : quels dossiers sont « au portefeuille CGA » à une date passée, quelles échéances TEE comptent dans l'année et le trimestre, quels champs sont requis pour la complétude, ce que devient une génération refaite avant transmission. Les choix ci-dessous sont paramétrables ou isolés dans un seul service, pour pouvoir être ajustés sans reprise.

## Décisions

1. **Portefeuille CGA à la date d'arrêté** (`Member::regulatoryAt`) : un dossier est retenu quand son contrat CGA est en vigueur le dernier jour du trimestre — entré au portefeuille (`adhesion_date`) au plus tard ce jour, sans radiation prenant effet ce jour ou avant (`cga_contract_ended_on`). Un dossier radié ou basculé au Cabinet après l'arrêté reste dans les états du trimestre ; un dossier Cabinet sans contrat n'y est jamais (RG-PTF-08, RG-SEC-08). Pour la date du jour, `regulatory()` reste la règle.
2. **Régime à une date** : d'après `member_regime_history` ; sans historique, le régime du dossier.
3. **Suivi TEE** : échéances de paiement TEE non annulées dont la date limite tombe dans l'année jusqu'à l'arrêté (annuel) ou dans le trimestre ; acquitté = paiements confirmés au plus tard à l'arrêté ; nombre de mois = mois couverts des échéances soldées ; reste et taux sur le montant après abattement.
4. **Suivi RME** : paiements confirmés du trimestre par nature ; « Autres (FDFP) » reprend FDFP, l'impôt des micro-entreprises et les natures sans colonne (`config/reports.php`, `rme_columns`). CNPS et CMU, cotisations sociales, n'y figurent pas (elles figurent dans les recettes du trimestre).
5. **Surclassements** : changement de régime du trimestre (TEE → RME, RME → RSI) pour un dossier au portefeuille CGA la veille ; CA et cotisations N-3 à N-1 (`upgrade_history_years`).
6. **Résiliations** : radiations validées, non annulées, rattachées au trimestre (`report_year`, `report_quarter`, fixés à la validation), classées selon le régime la veille de l'effet ; reste à recouvrer de l'année jusqu'à l'effet.
7. **CA de référence** : réalisé de l'année, à défaut prévisionnel, dernière année connue jusqu'à celle du rapport.
8. **Complétude (RG-RPT-02)** : section 1 = champs de la fiche technique (`section_1_fields`) ; 2 = au moins un dirigeant actif, et ses ITS, IGR, CNPS du trimestre ; 3 = une ligne de moyens par catégorie (hors « autres ») ; 4 et 6 = champs déclarés requis dans `config/reports.php` (`content`, synthèse qualitative en section 6) ; 5 = états calculés, toujours complète. « Néant » et 0 sont des réponses.
9. **Création** : trimestre en cours ou précédent ; échéances fixées à la création (`report.deadline_rule`, `report.legal_offset_days`) ; nombres de SMT et de bilans proposés depuis la campagne de clôture de l'exercice N-1 (RG-OBL-12).
10. **Versions** : la génération enregistre l'objet de données complet dans la version en cours, avec la règle « augmentée » en vigueur. Avant transmission, une nouvelle génération remplace l'instantané de cette version (documents en version suivante sous le même numéro) ; une modification après génération remet le rapport en brouillon. Après transmission, « Corriger » ouvre la version suivante avec son motif ; le CNCGA garde l'état et la synthèse de la dernière transmission jusqu'à la suivante ; l'accusé de la version précédente est effacé du rapport (il reste au journal des modifications).
11. **Documents** : jusqu'au rapport modernisé (étape 5.11), le rapport Word et PDF est le modèle `quarterly_report_docx` ; le classeur officiel est produit si `report.legacy_workbook_enabled`. Un **bordereau de transmission** provisoire est ajouté (`report_transmission_slip`, remplaçable par 3.12).
12. **Classeur officiel** : le premier bloc « CDI » de chaque feuille d'état sert de bloc type, reproduit pour chaque CDI ; la ligne TOTAL est recalculée par des formules `SUM` ; la feuille RESUME reçoit une ligne par CDI dans ses trois premiers tableaux. Le quatrième tableau de RESUME (« total des cotisations ») et les listes de clients de la fiche technique restent à préciser par DC-KNOWING : ils sont laissés vides (aucune donnée du portefeuille Cabinet n'y est mise).
13. **Échéances** (`reminders:reports`, 07:15) : relances aux décalages `report.reminder_offsets` autour de J tant que le rapport n'est pas transmis, « en retard » au lendemain de J, « hors délai réglementaire » au lendemain de J+15 ; aussi pour un centre qui n'a pas préparé son rapport. Idempotence : `reminders_sent` du rapport et une clé de cache par centre, trimestre et événement (`reminder_memory_days`). Les envois sont branchés au Lot 6.
14. **Vue du réseau** : `/network/quarterly-reports` (état de chaque CGA pour un trimestre) et `/network/quarterly-reports/{cga}/summary` (feuille Résumé agrégée, après transmission) ; aucun nom, NCC ni identifiant d'adhérent.
15. **Perte d'agrément (F-ORG-07)** : `/cga/agreement-loss/members` liste les dossiers du portefeuille CGA à radier avec le motif proposé ; aucune radiation n'est créée (RG-ADH-18).

## Preuve

`tests/Feature/Reports/` (PermanentProfileTest, PortfolioStatesTest — jeu de référence aux totaux calculés à la main —, QuarterlyReportTest, GenerateReportTest, TransmissionTest) et `tests/Unit/Reports/IncreaseRuleTest.php` (noms contenant RG-RPT-01, 02, 04 à 08, 10, 11).

## Points ouverts

- Validation par DC-KNOWING des règles 3 à 7 sur ses données réelles anonymisées (critère de l'étape 5.2).
- Contrôle visuel du classeur rempli dans Excel et LibreOffice (critère de l'étape 5.4) ; colonnes des feuilles « augmentée » et quatrième tableau de RESUME à préciser.
