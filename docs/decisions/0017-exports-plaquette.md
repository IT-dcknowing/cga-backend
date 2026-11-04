# 0017 — Exports Excel en tâche de fond et plaquette statistique annuelle

- **Statut** : proposée — interprétations à confirmer par le CNCGA et DC-KNOWING (points ouverts ci-dessous)
- **Date** : 05/11/2026
- **Étape** : 06-IMPLEMENTATION-PLAN, Lot 8, étape 8.3 (backend)
- **Références** :
  - 01-PRD : M17 (F-EXP-01), RG-SEC-06, RG-GEN-03, RG-PTF-02, RG-PTF-06, M15 (F-STA-03) ;
  - 02-TRD : §1 (tout ce qui est long est asynchrone), §4.2 (OpenSpout), §8.2 (chaîne documentaire) ;
  - 03-APPFLOW : §7 (opération longue) ;
  - décisions 0013 (formations), 0016 (statistiques).

## Contexte

Le PRD liste les exports : adhérents, cotisations, préoccupations, inscrits aux formations, échéances et statistiques. Il les veut horodatés, filtrés par période et limités au périmètre de la personne qui exporte. Il prévoit aussi une plaquette PDF des statistiques de l'année.

Il ne précise pas :
- la forme du suivi ;
- ce que voit chaque espace ;
- l'horodatage ;
- la chaîne de production de la plaquette.

## Décisions

### 1. API et suivi

- `POST /{network|cga}/exports` (`type`, période facultative `period`/`date`/`from`/`to`, `filters`) crée un export « en file d'attente » et répond **202**.
- Le Job `GenerateExport` (file `imports`) produit le classeur **dans l'espace et avec les droits de la personne** qui l'a demandé.
- `GET /{network|cga}/exports` liste ses derniers exports, au plus `cga.exports.history_items`.
- `GET /{network|cga}/exports/{export}` donne :
  - l'avancement (`queued`, `processing`, `done`, `failed`) ;
  - le nombre de lignes ;
  - le lien de téléchargement temporaire signé du fichier.
- Une personne ne voit que ses propres exports. Ceux d'une autre personne lui sont refusés (403). Ceux d'un autre espace n'existent pas pour elle (404).
- À la fin, la personne reçoit la notification « Export prêt » dans la cloche, et le journal d'audit trace un événement `export` avec la liste, le nombre de lignes et les filtres.

### 2. Listes et périmètre (RG-SEC-06)

Il faut le droit `exports.run` (Présidente, gestionnaire, responsables des cotisations et des formations, gérant, expert-comptable) **et** le droit de la liste exportée.

| Espace | Liste (`type`) | Droit de la liste | Période sur | Filtres |
|---|---|---|---|---|
| CGA | `members` (adhérents) | `members.view` | date d'adhésion | `portfolio` (`cga` par défaut ; `cabinet`, `all` avec `members.cabinet.view`), `statuses`, `regimes` |
| CGA | `concerns` (reçues des adhérents) | `concerns.reply` | date de dépôt | `statuses`, `categories` |
| CGA | `obligations` (échéances) | `obligations.manage` | date d'échéance | `categories`, `statuses` |
| CGA / CNCGA | `training_registrations` (inscrits de l'organisateur) | `trainings.cga.manage` / `trainings.network.manage` | date de la session | `statuses` |
| CNCGA | `dues` (cotisations) | `dues.view` | années de la période (l'année en cours sans période) | `statuses` |
| CNCGA | `network_concerns` (demandes des CGA) | `network_concerns.view` | date de dépôt | `statuses`, `categories` |
| CNCGA | `statistics` (statistiques nationales) | `statistics.national.view` | période (l'année en cours sans période) | — |

Les règles des listes s'appliquent à l'identique :
- les dossiers Cabinet exigent `members.cabinet.view` (RG-PTF-02) ;
- l'apporteur exige `members.referrers.view`, pour un dossier du portefeuille CGA seulement (RG-PTF-06) ;
- l'entreprise d'un adhérent inscrit à une formation n'est exportée que pour un CGA organisateur, jamais pour le CNCGA (RG-SEC-01, décision 0013) ;
- les statistiques ne lisent que les agrégats (RG-STA-02, 05).

Un filtre inconnu ou une liste d'un autre espace donne 422. Un filtre de portefeuille hors des droits donne 422. Sans le droit de la liste, la réponse est 403.

### 3. Fichier horodaté

- Classeur Excel écrit en flux par OpenSpout : une feuille par liste, en-têtes en gras.
- Une dernière feuille **« Informations »** donne :
  - la liste ;
  - la date et l'heure de l'export ;
  - son auteur ;
  - l'espace ;
  - la période ;
  - les filtres ;
  - le nombre de lignes.
- Le nom du fichier est horodaté, par exemple `adherents-20260331-1800.xlsx`.
- Le fichier est rangé par `StorageService` (`orgs/{organisation}/exports/{année}/…`).

### 4. Plaquette statistique (F-STA-03)

- `POST /network/statistics/brochures` (`year`, de `cga.statistics.first_year` à l'année en cours) répond 202. La plaquette est émise par la **chaîne documentaire** :
  - nouveau type `statistics_brochure` ;
  - modèle Word provisoire versionné comme les autres (F-ADM-02) ;
  - PDF produit en file `documents`.
- `GET /network/statistics/brochures` liste les plaquettes avec leur PDF.
- Il faut `statistics.national.view` et `exports.run`.
- **Contenu** :
  - statistiques nationales de l'année et couverture ;
  - indicateurs macro, ou « Non disponible » sans référence ;
  - adhérents par régime ;
  - recettes par nature et par CGA ;
  - adhérents par secteur et par Direction régionale.
- Document interne au CNCGA : jamais visible d'un adhérent, pas de version publique en ligne.

## Preuve

`tests/Feature/Exports/ExportsTest.php`, test de cloisonnement, tests des modèles de documents, collection Postman, dossier « 48. Étape 8.3 — Exports Excel et plaquette ».

## Points ouverts

- **Conservation des fichiers d'export** (données nominatives) : aucune purge automatique pour l'instant. Une durée de conservation et sa tâche de purge restent à fixer avec DC-KNOWING (02-TRD, durées légales).
- **Mise en page de la plaquette** : le modèle est provisoire et sans graphiques. Le CNCGA fournira la maquette officielle (logo, graphiques).
- **Droit `exports.run`** : il est accordé par défaut aux seuls rôles du catalogue. Les comptables d'un CGA n'exportent pas sauf réglage.
