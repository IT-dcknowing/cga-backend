# 0010 — Lot 4 (frontend) : écrans des obligations et de la campagne de clôture

- **Statut** : proposée — interprétations à confirmer par DC-KNOWING
- **Date** : 03/11/2026
- **Étapes** : 06-IMPLEMENTATION-PLAN, Lot 4, étapes 4.5 à 4.9 et 4.11 (frontend) ; complément backend de l'étape 4.7
- **Références** : 01-PRD F-OBL-02 à 08, RG-OBL-02, 03, 07 à 12, RG-PTF-02 ; 03-APPFLOW §4.3, §5.2

## Contexte

Les API du Lot 4 sont en place (étapes 4.1 à 4.4, 4.9, 4.10). Ce lot les relie aux écrans prévus par le 03-APPFLOW. Les choix ci-dessous précisent ce que les documents laissent ouvert.

## Décisions

### 4.5 — Obligations proposées à l'inscription

1. Le bloc « Obligations proposées » de l'assistant affiche les propositions du moteur d'applicabilité du serveur (`GET /cga/obligation-types/suggestions`) pour le régime, l'effectif et le secteur saisis, cochées par défaut. Le serveur crée lui-même ses propositions à l'enregistrement du dossier ; l'écran les **confirme** (cases restées cochées) ou les **retire** (cases décochées), puis attribue les obligations ajoutées depuis le catalogue. Un échec de cette étape n'annule pas l'enregistrement du dossier : un message renvoie à l'onglet Obligations.
2. Sans le droit `obligations.manage` (secrétaire, par exemple), le bloc n'apparaît pas : les propositions restent « proposées » dans le dossier, à traiter par un agent habilité.

### 4.6 — Onglet Obligations du dossier

3. Les actions d'une ligne sont exactement celles que le serveur autorise (`can`). Une déclaration de l'adhérent se **confirme telle quelle**, se **corrige** (montant, date, montant dû ; date pour une déclaration) ou se **rejette avec motif**. Le dossier s'ouvre directement sur un onglet avec `?onglet=` (lien du planning).

### 4.7 — Planning et mobilisation des recettes

4. « Envoyer un rappel » depuis une ligne du planning ouvre l'envoi groupé prérempli avec les dossiers dont l'échéance n'est pas faite (`?membres=…&modele=deadline`) ; l'agent garde la main sur le message et les canaux, et la règle de validation au-delà du seuil (RG-COM-01) s'applique.
5. **Mobilisation des recettes** — aucune API ne la couvrait : ajout de `GET /cga/obligation-planning/revenue` (backend). Sur les échéances de **paiement** dont l'échéance tombe dans la période (par défaut du 1er janvier de l'année au jour), hors annulées : montant dû, payé, reste et **taux de recouvrement = payé / dû** (une décimale), au total, par nature d'impôt (CNPS et CMU comprises) et par contribuable (les plus gros restes d'abord, au plus `cga.obligations.revenue_members_limit`, 50 par défaut). Une échéance **sans montant dû** n'entre pas dans les montants : elle est comptée à part (« montants à saisir », RG-OBL-08). Les dossiers du portefeuille **Cabinet** n'y figurent qu'avec le droit `members.cabinet.view` (RG-PTF-02). Indicateur interne au centre : il n'alimente aucun document officiel.
6. L'onglet « Obligations du centre » ouvre `/cga/gestion-interne` ; l'onglet « Fiche permanente » s'y ajoutera à l'étape 5.7.

### 4.8 — Espace adhérent : obligations et accueil

7. `/adherent/obligations` présente les échéances de l'adhérent par catégorie, en blocs repliables (total dû de la catégorie en tête). « Signaler comme payé » (paiement) ou « Signaler comme fait » (déclaration) n'apparaît que si le serveur l'autorise (`can.declare`) ; pour un paiement, l'adhérent indique le montant, la date et, s'il le souhaite, une preuve (PDF, JPEG ou PNG) ; pour une déclaration, il confirme simplement l'avoir faite. Le signalement **reste à confirmer par le CGA** : la ligne affiche « Déclaré — en attente de confirmation par votre CGA » et l'état officiel ne change qu'à la confirmation (étape 4.6) ; un rejet s'affiche avec son motif.
8. Accueil adhérent, sans sélecteur de période : prochaine échéance à traiter, ce que l'adhérent doit (impôts, CNPS et CMU), échéances du mois, reste des honoraires, documents récents. Une échéance **déjà signalée** au CGA ou une **diligence du CGA** ne demande rien à l'adhérent : elle n'est pas comptée « à traiter ».

### 4.9 — Catalogue national des obligations

9. L'écran du catalogue est un onglet « Catalogue des obligations » de `/cncga/parametres`, à côté des référentiels, réservé au droit `referentials.manage`. Il liste les versions chargées (en vigueur, provisoire ou validée). Le chargement se fait en deux temps : **aperçu** des effets du fichier, en nombres (types ajoutés, retirés, modifiés ; obligations proposées, retirées, terminées, échéances recalculées ; dossiers et centres touchés), puis **application** après confirmation. Une version provisoire se valide explicitement. Aucun nom d'adhérent n'est affiché au CNCGA.

## Preuve

Frontend : `new-member-page.test.tsx` (bloc 4.5), `obligations-tab.test.tsx`, `planning-page.test.tsx`, `internal-page.test.tsx`, `member-obligations-page.test.tsx`, `member-home-page.test.tsx`, `catalog-tab.test.tsx`. Backend : `tests/Feature/Obligations/RevenueMobilizationTest.php`.

## Points ouverts

- Définition du taux de recouvrement et période par défaut de la mobilisation des recettes à confirmer par DC-KNOWING.
