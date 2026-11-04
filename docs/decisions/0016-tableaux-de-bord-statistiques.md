# 0016 — Tableaux de bord et statistiques : période, contenu des blocs, droits, carte, « Actualiser »

- **Statut** : proposée — interprétations à confirmer par le CNCGA et DC-KNOWING (points ouverts ci-dessous)
- **Date** : 05/11/2026
- **Étape** : 06-IMPLEMENTATION-PLAN, Lot 8, étape 8.2 (backend)
- **Références** : 01-PRD M15 (F-DSH-01 à 04, F-STA-01, F-STA-02, RG-STA-01 à 05) ; 03-APPFLOW §3.2, §4.3, §5.2 ; 02-TRD §9 ; décision 0015 (agrégats)

## Contexte

Le PRD liste le contenu des trois tableaux de bord, le sélecteur de période et les statistiques nationales. Il ne précise pas :
- qui voit chaque bloc ;
- comment comparer une période à N-1 ;
- comment placer la carte (lieu du CGA ou commune de l'adhérent) ;
- ce qu'est une formation « presque complète ».

## Décisions

### 1. API

| Route | Écran | Accès |
|---|---|---|
| `GET /network/dashboard` | `/cncga/tableau-de-bord` (F-DSH-01) | tout agent du CNCGA |
| `GET /cga/dashboard` | `/cga/tableau-de-bord` (F-DSH-02) | tout agent d'un CGA |
| `GET /member/dashboard` | `/adherent/accueil` (F-DSH-03) | l'adhérent dans son espace |
| `GET /network/statistics` | `/cncga/statistiques` (F-STA-01) | `statistics.national.view` |
| `GET /network/statistics/map` et `/map/{regionalDirectorate}` | carte par DR, détail par commune | `statistics.national.view` |
| `GET /cga/statistics` | `/cga/statistiques` (F-STA-02) | `statistics.position.view` |
| `POST /{network\|cga}/statistics/refresh` | bouton « Actualiser » | tout agent du CNCGA ou d'un CGA |

**Chaque bloc d'un tableau de bord n'est rendu qu'avec le droit de l'écran qu'il ouvre.** Par exemple :
- les cotisations demandent `dues.view` ;
- les honoraires demandent `member_payments.manage` ;
- les alertes de radiation demandent `members.radiation.validate`.

Un bloc absent n'apparaît pas dans la réponse.

### 2. Période (F-DSH-04)

- `period` vaut `day`, `month` (par défaut), `year` (année civile) ou `custom` (`from`, `to`).
- La date de référence `date` vaut aujourd'hui par défaut.
- Aucune date future n'est acceptée. Une période personnalisée compte au plus `cga.statistics.max_period_days` jours.
- La comparaison porte sur la même période un an plus tôt : un 29 février devient le 28.
- Chaque indicateur de période donne valeur, N-1, écart et écart en % (null si N-1 vaut 0).
- Une période en cours s'arrête au jour même.

### 3. CNCGA

**Indicateurs :**
- CGA abonnés sur le registre ;
- **cotisations recouvrées** dans la période (N-1), avec le recouvrement de l'année à la fin de la période sur l'attendu de l'année ;
- **préoccupations des CGA** : ouvertes ou en cours aujourd'hui, et déposées dans la période (N-1) ;
- **adhérents du réseau** : portefeuille CGA des CGA abonnés à la fin de la période, lu dans les agrégats (N-1).

**Graphique** : les mois de l'année, tirés de `stat_network_monthly`.

**Alertes :**
- rapports en retard non transmis ;
- CGA impayés de l'année ;
- agréments arrivant à échéance dans le plus grand délai de `agreements.alert_months` (liste des CGA) ;
- formations du CNCGA publiées, à venir, dont les places prises atteignent **`trainings.almost_full_percent`** (90 % par défaut).

### 4. CGA

Les chiffres des adhérents portent sur le **portefeuille CGA**, comme les agrégats et les listes que les alertes ouvrent.

**Indicateurs :**
- **adhérents** : actifs, nouveaux, radiés (agrégats, N-1), et dossiers à compléter ;
- **honoraires** : échus et encaissés (agrégats, N-1), et **impayés** (reste dû des échéances d'honoraires passées en impayé, échues dans la période) ;
- **échéances** sous `obligations.upcoming_days` jours ;
- **rapport du trimestre** : statut et avancement en %.

**Alertes :**
- reclassés au réel, dont ceux en retard de radiation ;
- dossiers à compléter ;
- dossiers avec des pièces attendues ;
- honoraires impayés, dont ceux depuis plus de `fees.alert_after_months` mois ;
- radiations à valider ;
- factures à certifier ;
- clôture en retard (l'alerte « SMT » du PRD) ;
- préoccupations sans réponse depuis `concerns.stale_days` jours.

### 5. Adhérent

Le tableau de bord de l'adhérent n'a pas de période. Il comprend :
- la prochaine échéance ;
- les impôts dus et les cotisations CNPS + CMU dues (« Total dû » de « Mes obligations ») ;
- les échéances du mois ;
- les honoraires : à jour ou impayés, montant, nombre de périodes, prochaine échéance ;
- la dernière annonce reçue, de son CGA ou du réseau ;
- les documents récents.

Les listes comptent au plus `cga.statistics.member_dashboard_items` lignes.

Un accès en consultation seule (radié) ne garde que ses documents (03-APPFLOW §5.2).

### 6. Statistiques nationales

Elles sont lues dans les seuls agrégats (CGA abonnés, portefeuille CGA) et donnent :
- la couverture de la période ;
- les indicateurs macro de l'année de fin de période (décision 0015) ;
- les recettes fiscales par nature et par CGA ;
- les adhérents par régime et par secteur (dernier jour calculé de chaque CGA).

**Carte :**
- chaque Direction régionale montre le chiffre d'affaires, le nombre d'adhérents et le nombre de CGA **des CGA situés dans la DR** ;
- les CGA sans DR sont regroupés à part ;
- le détail d'une DR donne les **adhérents domiciliés** dans chacune de ses communes.

### 7. Position du CGA

La position compare ses indicateurs de la période aux moyennes du réseau :
- adhérents actifs, nouveaux, radiés ;
- honoraires encaissés ;
- impôts payés ;
- chiffre d'affaires ;
- délai de réponse.

Les moyennes ne sont données qu'à partir de 5 CGA contributeurs (RG-STA-04). Sinon la réponse porte « Données insuffisantes ».

### 8. « Actualiser »

- Le bouton accepte une demande par organisation dans la fenêtre de `rate_limit.stats_refresh` (1 toutes les 10 minutes par défaut). Au-delà, il répond 429 avec le délai d'attente.
- Le recalcul part en file (202) :
  - pour un CGA, l'année de son centre ;
  - pour le CNCGA, tout le réseau et la vue mensuelle.

### 9. Temps de réponse

Le tableau de bord du CNCGA et les statistiques nationales répondent en moins de 500 ms sur un an et demi d'agrégats de 20 CGA. Le test le vérifie.

## Preuve

`tests/Feature/Statistics/DashboardsTest.php`, test de cloisonnement, collection Postman, dossier « 47. Étape 8.2 — Tableaux de bord et statistiques ».

## Points ouverts

- **Seuil d'une formation « presque complète »** : 90 % des places, réglable. Le PRD ne le fixe pas. À confirmer par le CNCGA.
- **Carte** : le chiffre d'affaires et les adhérents d'une DR suivent le lieu du CGA, et le détail par commune suit la commune de l'adhérent. À confirmer par le CNCGA.
- **« Actualiser »** est ouvert à tout agent du CNCGA ou d'un CGA (limité dans le temps). À restreindre au besoin.
