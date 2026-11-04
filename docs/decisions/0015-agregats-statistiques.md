# 0015 — Agrégats statistiques : contenu des jours, vue mensuelle, recalculs, couverture et seuil

- **Statut** : proposée — interprétations à confirmer par le CNCGA et DC-KNOWING (points ouverts ci-dessous)
- **Date** : 05/11/2026
- **Étape** : 06-IMPLEMENTATION-PLAN, Lot 8, étape 8.1 (backend)
- **Références** : 01-PRD M15 (F-DSH, F-STA-01 à 03, RG-STA-01 à 05) ; 02-TRD §9 et §11 ; 05-BACKEND-SCHEMA §11 ; décision 0007 (portefeuille CGA à une date)

## Contexte

Le schéma donne les colonnes de `stat_org_daily` et annonce une vue mensuelle `stat_network_monthly` (cotisations, rapports, CGA abonnés). Il ne précise pas :
- ce qu'est un « adhérent actif » un jour donné ;
- ce que comptent les honoraires et les impôts ;
- comment une période se calcule à partir des jours ;
- quels CGA sont calculés.

## Décisions

### 1. Un jour de `stat_org_daily` (portefeuille CGA seul, RG-STA-05)

Un dossier compte un jour donné si son contrat CGA est en vigueur ce jour-là, selon la règle de `Member::regulatoryAt` (décision 0007). Son régime est celui du jour, d'après l'historique des régimes, comme dans les états du rapport trimestriel.

**Stocks du jour :**
- `active_members` = `members_rme` + `members_tee` + `members_rsi`. `members_rsi` compte les dossiers reclassés RSI **ou RNI** qui restent au portefeuille CGA en attendant leur radiation.
- `members_by_sector` et `members_by_commune` : nombre de dossiers par identifiant de référentiel, `none` quand il n'est pas renseigné. Le secteur et la commune sont ceux d'aujourd'hui (sans historique).
- `declared_revenue` : somme des chiffres d'affaires de référence **de l'année du jour**. Pour chaque dossier, on prend le réalisé, à défaut le prévisionnel. Une valeur Compta Flow remplace la saisie dès l'enregistrement (RG-DATA-06).

**Flux du jour :**
- `new_members` : entrées au portefeuille CGA (date d'adhésion).
- `radiations` : radiations validées, non annulées, qui prennent effet ce jour-là, pour un dossier au portefeuille CGA la veille.
- `fees_due` : échéances d'**honoraires** non annulées, à leur date d'échéance. Le droit d'adhésion et les formations ne sont pas des honoraires et ne comptent pas.
- `fees_paid` : part des paiements confirmés affectée aux honoraires, à la date du paiement.
- `tax_paid_total` et `tax_paid_by_nature` : impôts et cotisations payés confirmés, à la date du paiement. Toutes les natures figurent, à 0 par défaut.
- Préoccupations d'adhérents (niveau adhérent → CGA) d'un dossier au portefeuille CGA le jour de la demande :
  - ouvertes, au jour de la demande ;
  - closes (traitées ou rejetées), au jour de la clôture ;
  - premières réponses, au jour de la réponse, avec le délai moyen en heures.

**Ajouts au schéma :**
- `concerns_responded` (nombre de premières réponses du jour) : le délai moyen d'une période est pondéré par le nombre de réponses, jamais une moyenne de moyennes ;
- `computed_at`.

### 2. Une période

C'est une dimension des agrégats (02-TRD §9) :
- les flux sont sommés sur ses jours ;
- les stocks sont ceux de son dernier jour calculé (`OrgStatsSummary`, utilisé par l'API de l'étape 8.2).

### 3. CGA calculés

- Seuls les CGA abonnés (essai ou abonnement en cours, hors CGA radiés du réseau) reçoivent des agrégats économiques (RG-STA-02).
- Les jours de l'année sont calculés même avant l'abonnement : historique importé.
- Les lignes d'un CGA dont l'abonnement prend fin sont gardées.

### 4. `stat_network_monthly`

C'est une vue matérialisée PostgreSQL sur **tous** les CGA (statistiques institutionnelles, RG-STA-02). Elle va du premier mois des cotisations et des rapports jusqu'au mois en cours. Pour chaque mois :
- CGA inscrits au registre et CGA abonnés à la fin du mois ;
- cotisations attendues de l'année, recouvrées dans le mois, recouvrées sur l'année à la fin du mois ;
- CGA à jour de leur cotisation ;
- rapports attendus dans le mois, transmis, transmis en retard.

### 5. Recalculs

- **Chaque nuit à 02:00** (`stats:refresh`, horaire `SCHEDULE_STATS_REFRESH`) : l'année en cours de chaque CGA abonné, puis la vue mensuelle.
  - `--year` recalcule une année passée.
  - `--organization` ne recalcule qu'un CGA.
- **Recalcul ciblé** de l'année du CGA par le Job `RefreshOrganizationStatsJob` :
  - **déclenché par** :
    - un paiement d'honoraires confirmé ;
    - une radiation validée ou annulée ;
    - une adhésion validée ;
    - une bascule vers le portefeuille CGA ;
    - un paiement d'impôt enregistré, ou une déclaration de paiement confirmée ;
  - **dédoublonné par CGA pendant 5 minutes** (`ShouldBeUniqueUntilProcessing`) : un événement survenu pendant un calcul en programme un nouveau ;
  - programmé **après la validation de la transaction**, avec un délai de 5 minutes, pour regrouper les événements rapprochés.

  Le verrou de dédoublonnage est donc pris hors de toute transaction métier. Sous PostgreSQL, un verrou déjà pris dans le cache en base ferait échouer la transaction entière.

  Son magasin est réglable (`REFRESH_STATS_LOCK_STORE`) : par défaut celui du cache, et la mémoire pendant les tests.
- Le bouton « Actualiser » (limité à une fois toutes les 10 minutes) relève de l'API des tableaux de bord (étape 8.2).

### 6. Couverture (RG-STA-01)

« Sur la base de x CGA abonnés sur n » :
- **n** est le registre des CGA (hors CGA radiés du réseau), jamais 111 écrit dans le code ;
- **x** compte les CGA abonnés du jour, ou les CGA dont les agrégats figurent dans la période.

### 7. Moyennes du réseau (RG-STA-04)

- Une moyenne n'est donnée que si au moins **`stats.min_cgas_for_average`** CGA y contribuent. Ce paramètre réseau vaut **5** par défaut, avec un minimum de 2.
- Sinon la valeur est nulle (« données insuffisantes »).
- Seuls la moyenne et le nombre de contributeurs sortent, jamais le chiffre d'un autre CGA.

### 8. Indicateurs macro (F-STA-01, RG-STA-03)

- **Recettes fiscales du réseau** : impôts et cotisations payés confirmés de l'année.
- **Chiffre d'affaires des adhérents** : CA de référence de l'année au dernier jour calculé.
- **Contributions** : rapport aux références nationales de l'année. Une contribution reste « Non disponible — saisir les références {année} » tant que la référence n'est pas saisie.

## Preuve

`tests/Feature/Statistics/StatisticsTest.php` couvre RG-STA-01 à 05 sur un jeu de référence du 1er trimestre 2026, avec :
- le cloisonnement des lignes ;
- la commande planifiée ;
- le recalcul ciblé dédoublonné ;
- la journalisation des erreurs.

## Points ouverts

- `members_rsi` compte aussi les dossiers RNI, comme l'alerte « reclassés au réel » (filtre RSI, RNI). À confirmer par DC-KNOWING.
- Les honoraires excluent le droit d'adhésion. À confirmer par DC-KNOWING.
- Le secteur et la commune n'ont pas d'historique : les répartitions passées suivent la fiche actuelle.
- Le chiffre d'affaires d'une année sans valeur (année en cours avant toute saisie) compte pour 0. Aucun report de l'année précédente n'est fait : la règle « dernière année connue » vaut pour le rapport trimestriel seulement.
