# 01 — PRD · Document d'exigences produit · CGA Connect

> Version 1.4 · 15/10/2026 (précisions du Directeur du 15/10/2026, §0.2)
> Sources :
> - maquette `cga-connect-v4-1.html` (référence fonctionnelle) ;
> - réponses du Directeur (`Reponses_Questionnaire_CGA_Connect.pdf`, KEYMAN Constant, octobre 2026) ;
> - **compte rendu de la réunion de cadrage du 14/10/2026** (`compte_rendu_cga_connect.pdf`), qui **prévaut** sur les sources antérieures en cas de différence (§0).
> Ce document dit **quoi** construire. Le **comment** est dans le 02-TRD, les écrans et la navigation dans le 03-APPFLOW, l'apparence dans le 04-UX-UI, les données dans le 05-BACKEND-SCHEMA et l'ordre de construction dans le 06-IMPLEMENTATION-PLAN.
> Les identifiants `F-xxx` (fonctionnalités) et `RG-xxx` (règles de gestion) sont repris dans tous les documents.

---

## 0. Décisions récentes (prévalent sur le reste du document)

### 0.1 Compte rendu du 14/10/2026

| ID | Décision | Ce qui change dans ce PRD |
|---|---|---|
| CR-01 | Trois portefeuilles : **CGA** (TEE / RME sous contrat CGA, seul portefeuille déclaré au SP-CGA et à la DGI), **Cabinet** (RSI, Réel normal, TEE / RME sans contrat CGA, géré exclusivement par le cabinet), sous-portefeuille **« Accessoires »** (dossiers d'apporteurs d'affaires, dans le portefeuille CGA seulement — D-06) | Nouveau module M04-PTF ; régimes RSI et RNI admis dans le portefeuille Cabinet |
| CR-02 | Bascule Cabinet ↔ CGA possible ; la **signature du contrat CGA** est le préalable obligatoire à l'entrée dans le portefeuille CGA | RG-PTF-03 à 05 ; le reclassement au réel bascule le dossier vers le portefeuille Cabinet au lieu de le faire sortir de l'application |
| CR-03 | Cotisation institutionnelle distincte de l'offre plateforme ; **prorata temporis semestriel** pour un nouvel entrant | RG-COT-02 confirmé |
| CR-04 | **Aucune suspension automatique** pour impayé (cotisation ou plateforme) : toute interruption de service est décidée manuellement | RG-PAY-05, RG-ADH-09, RG-COT-06 : alertes et décisions manuelles uniquement |
| CR-05 | Le CGA est le seul pont avec la DGI ; **aucune transmission directe de données** au SP-CGA par la plateforme | Confirme F-RPT-04 (transmission faite par le CGA, enregistrée dans la plateforme) |
| CR-06 | Radiation : 3 initiateurs (CGA / cabinet, contribuable, SP-CGA) avec leurs motifs et modalités | F-ADH-06 refondu : initiateur, motifs, radiation « en cours » puis « validée » |
| CR-07 | **Exclusivité d'adhésion** : création **bloquée** tant que la radiation dans le CGA d'origine n'est pas validée | RG-ADH-05 remplacé (le blocage remplace l'alerte) |
| CR-08 | Rapport trimestriel **modernisé** (visuel, diagrammes, analyse qualitative) sans changer les agrégats réglementaires ; alimentation automatique depuis **Compta Flow** | M09 refondu ; le classeur de 2002 devient un export de compatibilité |
| CR-09 | Relances du rapport à **J-15, J-5, J+5**, échéance réglementaire stricte à **J+15** | RG-RPT-06 et 07 refondus, paramétrables |
| CR-10 | SMT et états financiers : dépôt légal **30 mai N+1**, calendrier interne paramétrable (30/01, 28/02, 31/03) | Nouvelle fonctionnalité F-OBL-08 « Campagne de clôture » ; RG-OBL-10 remplacé |
| CR-11 | Dossier radié : accès suspendu après 6 mois, données conservées **10 ans** | Confirme RG-ADH-12 et §7 |
| CR-12 | **Double numérotation** des dossiers repris (ancien n° + n° CGA Connect) ; identifiant chronologique unique pour les nouveaux | RG-IMP-01 et RG-ADH-03 mis à jour |
| CR-13 | **Habilitations par utilisateur** (« nuage de fonctionnalités » à cases à cocher) ; profils juriste, fiscaliste, chargé des cotisations, formateur | RG-ROLE-07 ; nouveaux profils §3.1 |
| CR-14 | Factures d'honoraires et de cotisations **conformes FNE** (facture normalisée électronique de la DGI) | Nouveau F-PAY-06 et RG-FNE-01 à 05 |
| CR-15 | En cas d'écart de chiffre d'affaires, **Compta Flow fait foi** ; module d'import pour les structures sans Flow | RG-DATA-06 ; connecteur Compta Flow F-INT-03 (lecture) entre dans le périmètre |
| CR-16 | API SMS et WhatsApp Business pour les rappels et la sensibilisation | Confirme M13 et RG-NOT-05 |
| CR-17 | Score de bancabilité : **solvabilité / poids 60 %, conformité 15 %, assiduité / réactivité 15 %, avis bancaire 10 %**, à affiner avec les banques | F-BNK-03 : nouveaux critères par défaut (paramétrables) |

### 0.2 Précisions du Directeur du 15/10/2026

| ID | Précision | Ce qui change |
|---|---|---|
| D-01 | Les **deux portefeuilles (CGA et Cabinet comptable)** existent dans chaque centre | RG-PTF-01 : le portefeuille Cabinet n'est plus un module à activer |
| D-02 | Un adhérent appartient au portefeuille CGA **si et seulement si un contrat** le lie au CGA | Nouvelle règle RG-PTF-08 (invariant contrat ↔ portefeuille CGA) |
| D-03 | Un adhérent CGA peut devenir client du Cabinet et inversement | RG-PTF-03 et 04 : deux actions de bascule symétriques |
| D-04 | **Toute radiation est faite manuellement** | Nouvelle règle RG-ADH-18 ; F-ORG-07 ne radie plus rien automatiquement |
| D-05 | Les habilitations se gèrent dans une **interface dédiée à chaque rôle**, avec en plus les ajustements par utilisateur | F-ADM-05 refondu ; RG-ROLE-07 conservé |
| D-06 | Le dossier d'un apporteur d'affaires porte l'étiquette « Accessoire » et n'existe **que dans le portefeuille CGA** | RG-PTF-06 et RG-SEC-08 mis à jour |

---

## 1. Vision

### 1.1 Problème
Le CNCGA pilote aujourd'hui son réseau de **111 CGA** à la main : cotisations, rapports trimestriels, statistiques et contribution des CGA aux recettes fiscales. Les CGA ressaisissent en permanence les mêmes informations pour produire contrats, attestations et rapports.

### 1.2 Solution
CGA Connect est une **plateforme web SaaS commune** au CNCGA, aux CGA et à leurs adhérents, avec trois espaces cloisonnés :

| Espace | Utilisateurs | Rôle de l'espace |
|---|---|---|
| **CNCGA** | Présidente, gestionnaires, bureau | Piloter le réseau : registre des CGA, cotisations, préoccupations des CGA, formations, annonces, statistiques nationales, carte économique |
| **CGA** | Gérant, expert-comptable, comptables, secrétaire, juriste | Gérer les adhérents : inscription, documents officiels, honoraires, obligations fiscales et sociales, rapport trimestriel, préoccupations, formations |
| **Adhérent** | Dirigeant de l'entreprise adhérente et collaborateurs invités | Consulter ses obligations et échéances, télécharger ses documents, écrire à son CGA, s'inscrire aux formations |

Plus une **page publique de vérification** des documents par QR code, sans compte.

### 1.3 Les 3 fonctions indispensables au lancement
1. Cycle de vie des adhérents avec production automatique du contrat et de l'attestation au format normé DGI.
2. Rapport trimestriel généré automatiquement : rapport modernisé (PDF et Word, avec graphiques et analyse), sur le seul portefeuille CGA, et classeur Excel du SP-CGA en export de compatibilité.
3. Suivi des cotisations CNCGA et tableau de bord du réseau.

Viennent juste après : le suivi des échéances et les rappels aux adhérents.

### 1.4 Critères de réussite à 12 mois
- 15 à 20 CGA abonnés et actifs.
- 100 % de leurs rapports trimestriels produits par la plateforme et transmis dans les délais.
- Plus de 1 000 adhérents avec un accès.
- Recouvrement des cotisations CNCGA en progression mesurable.
- Zéro document non conforme aux formats DGI.

### 1.5 Contraintes du projet
- Équipe : 1 développeur backend, 1 développeur frontend, 1 personne pour la recette et la validation.
- Objectif : une version utilisable mise en production le plus vite possible, en respectant les règles de gestion ci-dessous.
- Langue : français. Monnaie : FCFA, sans décimales. Pays : Côte d'Ivoire (fuseau Africa/Abidjan, UTC+0). L'exercice = l'année civile.
- La majorité des adhérents utilise un téléphone ; beaucoup n'ont pas d'e-mail.

---

## 2. Périmètre

### 2.1 Dans le périmètre
Toutes les fonctionnalités de la section 6, sauf celles marquées « Évolution ».

### 2.2 Hors périmètre
| Sujet | Décision |
|---|---|
| Télédéclaration (e-impôts, e-CNPS) | Hors périmètre. CGA Connect prépare, trace et relance ; la déclaration reste faite par le CGA sur les plateformes officielles. Une interconnexion est une évolution. |
| Suivi de conformité juridique détaillé | Relève de Legal Flow (application externe). CGA Connect affiche au plus un niveau de conformité déclaré par le CGA (bancabilité). |
| Écrans Legal Flow de la maquette (« Legal Flow — Conformité », « Legal Flow — Mon planning ») | Non construits. |
| Accès de la DGI et des banques | Aucun compte. Elles reçoivent des documents et peuvent consulter la page publique de vérification. |
| Paiement en ligne (mobile money, carte) | Hors périmètre. Les paiements sont enregistrés ou déclarés, jamais encaissés par la plateforme. |
| Facturation de l'abonnement SaaS | Gérée hors plateforme. La plateforme ne connaît que l'état d'abonnement de chaque CGA. |
| Connexion aux applications Flow | **Compta Flow en lecture entre dans le périmètre** (CR-08, CR-15 : chiffre d'affaires et paiements d'impôts pour le rapport et les statistiques), dans un lot dédié, conditionné à la disponibilité de son API. Sell Flow et RH Flow restent des évolutions. Les écrans « Suivi des adhérents » et les onglets prestations, charges et trésorerie de « Gestion interne » ne sont pas construits en V1. |
| Transmission directe au SP-CGA ou à la DGI | Exclue (CR-05) : la plateforme produit les documents, le CGA les transmet et enregistre la transmission. |
| Signature électronique certifiée | Évolution optionnelle. En V1 : image de signature et de cachet + cachet de vérification QR. |

### 2.3 Évolutions déjà identifiées (à ne pas construire, mais à ne pas rendre impossibles)
Pré-inscription en ligne des entreprises · public extérieur aux formations · affectation de portefeuilles par agent · suivi pas à pas de la procédure de radiation pour manquements graves · accès limité pour les CGA non abonnés · envoi des dossiers bancaires par e-mail · signature certifiée · interconnexion Legal Flow, Sell Flow, e-impôts · écriture vers Compta Flow · modification directe de son profil par l'adhérent.

---

## 3. Acteurs et rôles

### 3.1 Rôles

| Code | Espace | Libellé | Résumé |
|---|---|---|---|
| `cncga_president` | CNCGA | Président(e) | Tous les droits CNCGA, seul(e) à suspendre un CGA et à gérer les utilisateurs CNCGA |
| `cncga_manager` | CNCGA | Gestionnaire / secrétariat | Gestion courante du réseau (= « Agent CNCGA » du tableau validé) |
| `cncga_dues` | CNCGA | Responsable des cotisations | Cotisations et relances seulement |
| `cncga_training` | CNCGA | Responsable des formations | Formations et annonces seulement |
| `cncga_viewer` | CNCGA | Consultation (membres du bureau) | Lecture des tableaux de bord et des listes CNCGA |
| `cga_manager` | CGA | Gérant | Tous les droits CGA |
| `cga_accountant_expert` | CGA | Expert-comptable | Comme le gérant, sauf fiche du CGA (lecture) et utilisateurs |
| `cga_accountant` | CGA | Comptable | Gestion courante des adhérents, sans radiation |
| `cga_secretary` | CGA | Secrétaire / archiviste | Inscription, documents, rapport (saisie), préoccupations |
| `cga_legal` | CGA | Juriste | Défaut : droits du comptable (personnalisables par utilisateur, RG-ROLE-07) |
| `cga_tax_advisor` | CGA | Fiscaliste | Défaut : droits du comptable |
| `cga_billing_officer` | CGA | Chargé des cotisations et honoraires | Défaut : paiements, reçus et factures, relances d'honoraires, consultation des adhérents |
| `cga_trainer` | CGA | Formateur | Défaut : formations du CGA (création, inscrits, présence, attestations), consultation des adhérents |
| `member_owner` | Adhérent | Accès principal (dirigeant) | Consultation complète, paiements déclarés, consentements |
| `member_user` | Adhérent | Accès secondaire (ex. comptable interne) | [HYPOTHÈSE] comme l'accès principal, sauf les consentements (bancabilité) |
| `platform_admin` | Plateforme | Administrateur de la plateforme | Création des organisations, **gestion des droits de tout le réseau** (RG-ROLE-02), paramètres d'envoi SMS / WhatsApp / e-mail (RG-NOT-05), référentiels et modèles, support. Tout accès aux données d'un CGA est journalisé (RG-SEC-04) |

Les rôles cotisations et formations peuvent être tenus par la même personne : un utilisateur peut cumuler plusieurs rôles.

**Droits paramétrables (décision du 10/10/2026).** La liste des rôles est fixe, mais **les droits de chaque rôle sont paramétrables dans l'application** (F-ADM-05) :
- **RG-ROLE-01** Le tableau §3.3 est le **paramétrage par défaut**, chargé à l'installation.
- **RG-ROLE-00** Les droits se gèrent **rôle par rôle, chacun dans son interface dédiée** (F-ADM-05, D-05) : une page par rôle, qui liste tous ses droits regroupés par module.
- **RG-ROLE-02** L'**administrateur de la plateforme** gère les droits **de tout le réseau** : droits par défaut de tous les rôles (CNCGA, CGA, adhérent). Il peut aussi consulter les ajustements faits par chaque CGA.
- **RG-ROLE-03** Le **gérant** de chaque CGA gère les droits **de son centre** : il peut **ajuster les droits des rôles de son CGA** (comptable, juriste, secrétaire, expert-comptable) pour son seul centre ; sans ajustement, le paramétrage réseau s'applique. Il ne peut pas modifier ses propres droits de gérant.
- **RG-ROLE-04** Certains droits sont **verrouillés** et ne sont jamais paramétrables, car ils protègent la confiance du réseau :
  - aucun rôle CNCGA n'accède à une donnée nominative d'adhérent (RG-SEC-01) ;
  - aucun rôle n'accède aux données d'un autre CGA (RG-SEC-02) ;
  - seule la Présidente suspend un CGA ;
  - seul le gérant annule une radiation et gère les utilisateurs de son CGA ;
  - un adhérent ne voit que ses propres données ;
  - le consentement bancaire est réservé à l'accès principal de l'adhérent.
- **RG-ROLE-05** Toute modification de droits est journalisée (auteur, rôle, droit, avant / après) et prend effet immédiatement pour les utilisateurs concernés.
- **RG-ROLE-06** Un bouton « Revenir aux droits par défaut » existe au niveau du réseau et au niveau de chaque CGA.
- **RG-ROLE-07 — Habilitations par utilisateur (« nuage de fonctionnalités », CR-13, confirmé D-05).** En plus des droits du rôle (fixés dans l'interface de ce rôle), chaque utilisateur peut recevoir un **ajustement individuel** : cases à cocher droit par droit (accorder ou retirer), par rapport à son profil. Le gérant le fait pour les utilisateurs de son centre ; l'administrateur de la plateforme pour les utilisateurs du CNCGA. Les permissions verrouillées (RG-ROLE-04) restent hors d'atteinte. L'écran affiche, pour chaque droit, son origine (profil, ajustement du centre, ajustement individuel).

### 3.2 Règles de comptes
- **RG-ACC-01** Un compte unique par personne, identifié par son numéro de téléphone (obligatoire) et éventuellement son e-mail.
- **RG-ACC-02** Un compte peut avoir plusieurs rattachements (CNCGA, plusieurs CGA, un ou plusieurs adhérents). Après connexion, l'utilisateur choisit l'espace s'il en a plusieurs, et peut en changer sans se reconnecter.
- **RG-ACC-03** Le premier accès se fait toujours par **invitation** (lien par SMS ou e-mail) ; l'invité choisit son mot de passe. Validité du lien : 7 jours, renvoyable.
- **RG-ACC-04** Connexion par téléphone (ou e-mail) + mot de passe. Mot de passe oublié : code par SMS (ou e-mail si renseigné).
- **RG-ACC-05** Accès adhérents : seuls les adhérents que le CGA décide d'inviter ont un accès. Une entreprise peut avoir un accès principal et plusieurs accès secondaires, à la main du CGA.
- **RG-ACC-06** Un utilisateur inactif est désactivé après 12 mois sans connexion, puis supprimé à 24 mois après l'en avoir informé.
- **RG-ACC-07** Dans un CGA, chaque agent voit **tous** les adhérents du CGA.

### 3.3 Matrice des droits par défaut (validée par le Directeur, paramétrable selon §3.1)

✔ = autorisé · L = lecture · — = interdit. Colonnes CNCGA « Agent » = `cncga_manager`. Les lignes marquées 🔒 sont verrouillées (RG-ROLE-04). Les profils juriste et fiscaliste reçoivent par défaut les droits de la colonne « Comptable / Juriste » ; le chargé des cotisations et le formateur reçoivent les droits décrits au §3.1 ; tous sont ajustables par utilisateur (RG-ROLE-07).

| Action | Prés. CNCGA | Gest. CNCGA | Resp. cotis. | Resp. form. | Bureau | Gérant | Expert-c. | Comptable / Juriste | Secrétaire | Adhérent |
|---|---|---|---|---|---|---|---|---|---|---|
| Enregistrer ou modifier un CGA | ✔ | ✔ | — | — | L | — | — | — | — | — |
| Suspendre / réactiver un CGA 🔒 | ✔ | — | — | — | — | — | — | — | — | — |
| Gérer les utilisateurs CNCGA 🔒 | ✔ | — | — | — | — | — | — | — | — | — |
| Gérer les droits de tout le réseau 🔒 (administrateur de la plateforme uniquement) | — | — | — | — | — | — | — | — | — | — |
| Paramétrer les règles du réseau (rapport, cotisation, bancabilité) | ✔ | ✔ | — | — | — | — | — | — | — | — |
| Enregistrer / confirmer un paiement de cotisation | ✔ | ✔ | ✔ | — | L | — | — | — | — | — |
| Relancer une cotisation | ✔ | ✔ | ✔ | — | — | — | — | — | — | — |
| Publier une annonce réseau | ✔ | ✔ | — | ✔ | — | — | — | — | — | — |
| Créer une formation réseau | ✔ | ✔ | — | ✔ | — | — | — | — | — | — |
| Traiter une préoccupation d'un CGA | ✔ | ✔ | — | — | L | — | — | — | — | — |
| Consulter l'état de transmission et le tableau de synthèse des rapports | ✔ | ✔ | — | — | L | — | — | — | — | — |
| Statistiques nationales | ✔ | ✔ | L | L | L | L (position du CGA) | L (idem) | — | — | — |
| Modifier la fiche et les paramètres du CGA | — | — | — | — | — | ✔ | L | — | — | — |
| Gérer les utilisateurs du CGA 🔒 | — | — | — | — | — | ✔ | — | — | — | — |
| Ajuster les droits des rôles de son CGA 🔒 | — | — | — | — | — | ✔ | — | — | — | — |
| Ajuster les habilitations d'un utilisateur de son CGA 🔒 | — | — | — | — | — | ✔ | — | — | — | — |
| Paramétrer la facturation FNE et la connexion Compta Flow du CGA | — | — | — | — | — | ✔ | L | — | — | — |
| Inviter les adhérents en ligne et gérer leurs accès | — | — | — | — | — | ✔ | — | — | — | — |
| Inscrire un adhérent | — | — | — | — | — | ✔ | ✔ | ✔ | ✔ | — |
| Modifier un adhérent | — | — | — | — | — | ✔ | ✔ | ✔ | — | — |
| Demander une radiation | — | — | — | — | — | ✔ | ✔ | — | — | — |
| Valider une radiation | — | — | — | — | — | ✔ | ✔ | — | — | — |
| Basculer un dossier entre portefeuilles (Cabinet → CGA, CGA → Cabinet) | — | — | — | — | — | ✔ | ✔ | — | — | — |
| Voir et gérer le portefeuille Cabinet | — | — | — | — | — | ✔ | ✔ | ✔ | ✔ | — |
| Voir les apporteurs d'affaires (« Accessoires ») | — | — | — | — | — | ✔ | ✔ | — | — | — |
| Bloquer / débloquer l'attestation d'un adhérent | — | — | — | — | — | ✔ | — | — | — | — |
| Annuler une radiation (30 j) 🔒 | — | — | — | — | — | ✔ | — | — | — | — |
| Produire contrat et attestation | — | — | — | — | — | ✔ | ✔ | ✔ | ✔ | — |
| Annuler un document émis | — | — | — | — | — | ✔ | ✔ | — | — | — |
| Enregistrer / confirmer un paiement d'adhérent | — | — | — | — | — | ✔ | ✔ | ✔ | — | — |
| Émettre une facture et la soumettre à la certification FNE | ✔ (cotisations) | ✔ (cotisations) | ✔ (cotisations) | — | — | ✔ | ✔ | ✔ | — | — |
| Suivre la campagne de clôture (SMT, états financiers) | — | — | — | — | — | ✔ | ✔ | ✔ | L | pièces à remettre |
| Mettre à jour les obligations d'un adhérent | — | — | — | — | — | ✔ | ✔ | ✔ | — | déclarer un paiement |
| Envoyer un rappel groupé | — | — | — | — | — | ✔ | ✔ | ✔ | — | — |
| Valider un envoi groupé de plus de 50 destinataires | — | — | — | — | — | ✔ | — | — | — | — |
| Préparer le rapport trimestriel | — | — | — | — | — | ✔ | ✔ | ✔ | ✔ | — |
| Transmettre le rapport | — | — | — | — | — | ✔ | ✔ | — | — | — |
| Répondre aux préoccupations des adhérents | — | — | — | — | — | ✔ | ✔ | ✔ | ✔ | — |
| Écrire au CNCGA / lui transférer une demande | — | — | — | — | — | ✔ | ✔ | — | — | — |
| Créer une formation pour ses adhérents et agents | — | — | — | — | — | ✔ | ✔ | ✔ | ✔ | — |
| Gestion interne du CGA (fiche permanente, obligations propres) | — | — | — | — | — | ✔ | ✔ | — | — | — |
| Exporter des listes | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | — |
| Bancabilité (si activée) | — | — | — | — | — | ✔ | ✔ | ✔ | — | L + consentement |
| Consulter ses obligations, documents ; écrire à son CGA ; s'inscrire à une formation | — | — | — | — | — | — | — | — | — | ✔ |

### 3.4 Confidentialité (règles de confiance du réseau)
- **RG-SEC-01** Le CNCGA ne voit **jamais** de donnée nominative sur les adhérents d'un CGA : uniquement des chiffres agrégés (nombre, régimes, secteurs, montants totaux).
- **RG-SEC-02** Un CGA ne voit jamais les données d'un autre CGA. Exception : à l'inscription d'un NCC déjà adhérent ailleurs, la création est bloquée avec un message qui ne nomme pas le CGA concerné (RG-ADH-05).
- **RG-SEC-03** Le personnel de DC-KNOWING CGA, en tant que centre du réseau, n'a aucun droit sur les autres CGA.
- **RG-SEC-04** Seule l'équipe technique (`platform_admin`) peut accéder aux données d'un CGA, pour la maintenance, et chaque accès est journalisé. Un CGA peut obtenir le journal des accès à ses données.
- **RG-SEC-05 (règle stricte)** **Toute action dans l'application est journalisée, sans exception.** Cela comprend :
  - les consultations (listes, fiches, recherches), téléchargements, exports, connexions et échecs de connexion, changements d'espace ;
  - les accès refusés et les erreurs ;
  - les tâches automatiques, les envois de messages et les vérifications de documents par QR ;
  - toutes les créations, modifications et suppressions, avec l'ancienne et la nouvelle valeur.

  Chaque trace indique qui, quand, quoi, sur quel objet et avec quel résultat. Durées de conservation paramétrables (défaut : 2 ans pour le journal d'activité, 5 ans pour le journal des modifications).
- **RG-SEC-08** Le portefeuille Cabinet est une donnée **interne au centre** : jamais transmis au SP-CGA, jamais compté dans le rapport trimestriel ni dans les statistiques du CNCGA (RG-PTF-02). Les dossiers « Accessoires » sont des adhérents du portefeuille CGA et sont comptés normalement ; seule l'**étiquette d'apporteur** est interne : elle n'apparaît dans aucun document, rapport, export pour l'administration ni statistique (RG-PTF-06).
- **RG-SEC-06** Les exports sont limités au périmètre de l'utilisateur qui exporte et sont horodatés.
- **RG-SEC-07 (règle stricte)** L'application respecte **toujours les 10 risques de l'OWASP Top 10** (édition en vigueur) et **ne fait jamais confiance aux données venant du navigateur** : tout est revérifié par le serveur (droits, formats, appartenance des objets, montants, statuts). Les contrôles affichés à l'écran ne servent qu'au confort de l'utilisateur (02-TRD §6.7).

---

## 4. Glossaire

| Terme | Définition |
|---|---|
| CNCGA | Collectif National des Centres de Gestion Agréés (dénomination de la maquette) |
| CGA | Centre de Gestion Agréé, membre du réseau |
| SP-CGA | Secrétariat Permanent des CGA (DGI), destinataire officiel du rapport trimestriel |
| Adhérent | Entreprise (personne morale ou entreprise individuelle) liée à un CGA par un contrat d'adhésion |
| RME | Régime des microentreprises |
| TEE | Taxe d'État de l'Entreprenant |
| RSI | Régime du réel simplifié — plus géré par les CGA |
| NCC | Numéro de compte contribuable |
| CDI | Centre des impôts de rattachement |
| DR | Direction régionale des impôts |
| Droit d'adhésion | Montant payé une fois à l'adhésion (25 000 F par défaut) |
| Honoraires | Montant périodique dû par l'adhérent à son CGA |
| Cotisation CNCGA | Montant annuel dû par chaque CGA au CNCGA (750 000 F) — distinct de l'abonnement à la plateforme |
| Obligation | Déclaration, paiement ou diligence que l'adhérent doit accomplir auprès d'une administration |
| Préoccupation | Demande ou question écrite d'un adhérent à son CGA, ou d'un CGA au CNCGA |
| Cachet de vérification | QR code imprimé sur chaque document officiel, menant à la page publique de vérification |

---

## 5. Règles de gestion transverses

| ID | Règle |
|---|---|
| RG-GEN-01 | Chaque donnée métier appartient à une organisation (CNCGA ou un CGA). Un utilisateur n'agit que dans l'organisation de l'espace où il se trouve. |
| RG-GEN-02 | Les montants sont des entiers en FCFA. Les dates s'affichent au format jj/mm/aaaa. |
| RG-GEN-03 | Toute liste est paginée, filtrable et triable ; les listes principales sont exportables en Excel (F-EXP-01). |
| RG-GEN-04 | Les statuts utilisent un code couleur unique : vert = favorable, orange = à traiter ou en retard, gris = neutre, rouge = bloquant ou annulé. |
| RG-GEN-05 | Les valeurs marquées « paramétrable » ont une valeur par défaut au niveau réseau, modifiable par CGA lorsque c'est indiqué. |
| RG-GEN-06 | Les durées de conservation sont celles de la section 7. |
| RG-GEN-07 | **Règle stricte** : toute valeur susceptible de changer (délai, montant, seuil, format de numéro, durée, texte de message, limite) est **paramétrable**, jamais figée dans le programme (02-TRD §7.6). |

---

## 6. Fonctionnalités

Chaque fonctionnalité indique : acteur(s), description, règles, critères d'acceptation (CA).

### M01 — Accès et comptes

**F-AUTH-01 Connexion** · Tous
- Saisie téléphone (ou e-mail) + mot de passe. Après 5 échecs, blocage 15 minutes.
- S'il a plusieurs espaces, l'utilisateur arrive sur le sélecteur d'espace (F-AUTH-04).
- CA : un utilisateur sans rattachement actif ne peut pas se connecter (message « Votre accès n'est pas actif, contactez votre CGA »).

**F-AUTH-02 Activation par invitation** · Invité
- Lien reçu par SMS et/ou e-mail → page d'activation : nom affiché, choix du mot de passe (8 caractères minimum), acceptation des conditions d'utilisation et, pour les adhérents, des préférences SMS / WhatsApp.
- CA : un lien expiré ou déjà utilisé affiche « Lien invalide » avec le contact du CGA ou du CNCGA.

**F-AUTH-03 Mot de passe oublié** · Tous
- Saisie du téléphone → code à 6 chiffres par SMS (validité 10 minutes, 3 essais) → nouveau mot de passe. Par e-mail si le compte en a un.

**F-AUTH-04 Choix et changement d'espace** · Utilisateur multi-rôles
- Liste des espaces (ex. « CNCGA — Gestionnaire », « DC-KNOWING CGA — Expert-comptable », « INC SARL — Adhérent »). Le choix est mémorisé ; un menu dans l'en-tête permet de changer d'espace.

**F-AUTH-05 Mon profil** · Tous
- Modifier nom, e-mail, mot de passe, préférences de notification (RG-NOT-04). Le changement de téléphone exige un code SMS envoyé au nouveau numéro.

### M02 — Organisations (registre des CGA)

**F-ORG-01 Registre des CGA** · CNCGA
- Liste des 111 CGA du réseau (abonnés ou non) : dénomination, ville, DR, n° d'agrément, statut, état d'abonnement, nombre d'adhérents (abonnés seulement, calculé), état de la cotisation de l'année, état du dernier rapport.
- Filtres : statut, abonnement, DR, cotisation, rapport.
- **RG-ORG-01** Le registre est initialisé par import des 111 CGA (fiche minimale : dénomination, agrément, ville, contact) — F-IMP-01.
- **RG-ORG-02** Le nombre d'adhérents est toujours calculé, jamais saisi.

**F-ORG-02 Création d'un CGA** · CNCGA (président, gestionnaire)
- Fiche minimale : dénomination*, sigle, ville*, n° d'agrément, date d'agrément, téléphone*, e-mail, statut d'agrément (en cours / agréé).
- **RG-ORG-03** Un CGA dont l'agrément est « en cours » peut être créé et abonné, mais **ne peut pas inscrire d'adhérents** tant que l'agrément n'est pas renseigné.
- Option « Ouvrir l'accès » : invite le gérant (téléphone + nom) → crée son rattachement `cga_manager`.

**F-ORG-03 Complément de la fiche par le CGA** · Gérant
- Champs requis avant la production de tout document officiel : NCC, RCCM, forme juridique, capital, n° et date d'agrément, DR et CDI de rattachement, adresse complète, téléphones, e-mail, gérant, expert-comptable référent, **abréviation de numérotation** (2 à 6 lettres majuscules, unique dans le réseau), logo, image de signature du gérant, image du cachet.
- **RG-ORG-04** Tant que la fiche n'est pas complète, la production de documents est bloquée avec la liste des champs manquants.
- **RG-ORG-05** L'abréviation n'est plus modifiable après l'attribution du premier numéro d'adhésion.

**F-ORG-04 Fiche détaillée d'un CGA (vue CNCGA)** · CNCGA
- Onglets : Identité · Utilisateurs (noms et rôles, sans données d'adhérents) · Cotisations (historique) · Rapports (état de transmission par trimestre + tableau de synthèse) · Chiffres clés agrégés · Préoccupations du CGA.

**F-ORG-05 Abonnement** · CNCGA (président, gestionnaire), `platform_admin`
- États : `non_abonne` · `essai` (3 mois offerts aux pilotes) · `abonne` · `resilie`, avec dates.
- **RG-ORG-06** Un CGA non abonné figure au registre et dans le suivi des cotisations, mais n'a aucun accès. Ses statistiques institutionnelles (cotisation, rapport déclaré par le CNCGA) sont suivies ; ses statistiques économiques ne sont pas disponibles.
- **RG-ORG-07** Résiliation : le CGA reçoit un export complet (listes Excel + documents PDF) ; ses données sont supprimées 6 mois après la résiliation, sous réserve des durées légales (section 7). Le CNCGA conserve les agrégats anonymisés et l'historique des cotisations.

**F-ORG-06 Suspension et réactivation** · Présidente uniquement
- Motif obligatoire, tracé, réversible.
- **RG-ORG-08** CGA suspendu : ses agents ne peuvent que consulter ; ses adhérents gardent l'accès à leurs documents.
- **RG-ORG-09** Aucune suspension automatique (y compris pour impayé de cotisation).

**F-ORG-07 Perte d'agrément** · Présidente
- Marquer l'agrément « retiré » (date, motif) → le CGA est suspendu ; la plateforme **liste** ses adhérents du portefeuille CGA à radier (motif proposé « Perte d'agrément du CGA »), **sans radier aucun dossier** : chaque radiation est saisie et validée manuellement (RG-ADH-18), au cas par cas. Un adhérent volontaire peut adhérer à un autre CGA (RG-ADH-16).

**F-ORG-08 Alerte de renouvellement d'agrément** · Système
- **RG-ORG-10** Alerte au CGA et au CNCGA 6 mois puis 3 mois avant la date d'échéance de l'agrément. [À confirmer SP-CGA / DGI]

**F-ORG-09 Utilisateurs du CGA** · Gérant
- Inviter un agent (nom, téléphone, e-mail, rôle(s)), modifier ses rôles, désactiver son accès. Idem côté CNCGA pour la Présidente (F-ORG-10).

### M03 — Référentiels (administrés par le CNCGA)

**F-REF-01 Secteurs d'activité** — 8 secteurs de départ : Agriculture (AGR001), Commerce général (COM001), BTP (BTP001), Industrie / transformation (IND001), Services (SER001), Santé / pharmacie (SAN001), Transport (TRA001), Numérique (NUM001).
- **RG-REF-01** Un CGA peut **proposer** un secteur ; il est utilisable par ce CGA immédiatement avec le statut « proposé », et devient national après validation du CNCGA. Le CNCGA peut le rejeter ou le fusionner avec un secteur existant.
- **RG-REF-02** Chaque secteur peut être rattaché à des codes d'activité DGI (nomenclature à fournir).

**F-REF-02 Directions régionales, CDI, communes** — listes gérées par le CNCGA, importables.

**F-REF-03 Catalogue des obligations** — voir M07.

**F-REF-04 Références nationales annuelles** — recettes fiscales intérieures et PIB nominal de l'année, avec leur source (loi de finances, INS / BCEAO). Saisis par le CNCGA.

**F-REF-05 Paramètres réseau** — montant de la cotisation de l'année, dates limites, délais de relance, quotas de SMS, valeurs par défaut des règles « paramétrables ».

### M04 — Adhérents

**F-ADH-01 Liste des adhérents** · Agents CGA
- Colonnes : raison sociale, n° d'adhésion, date d'adhésion, NCC, régime (badge « RSI — à radier » si actif au RSI), statut, honoraires (à jour / impayé n mois), pièces (complètes / en attente), accès en ligne (oui / invité / non).
- Recherche : raison sociale, sigle, NCC, n° d'adhésion, téléphone. Filtres : régime, statut, secteur, CDI, honoraires, pièces, date d'adhésion.
- Onglet ou filtre « Dossiers à compléter » (brouillons).

**F-ADH-02 Inscription d'un adhérent** · Gérant, expert-comptable, comptable, juriste, secrétaire

Étape 1 — Identification :

| Champ | Obligatoire | Règle |
|---|---|---|
| Type | oui | Personne morale / Entreprise individuelle |
| Raison sociale | oui | Majuscules à l'enregistrement |
| Sigle | non | Si vide : initiales de la raison sociale |
| Forme juridique | oui | SARL, SARLU, SAS, SASU, SA, SNC, GIE, Coopérative, Entreprise individuelle, Autre |
| Capital social | non (personne morale) | Montant en FCFA |
| Nom, prénoms, nationalité, type et n° de pièce d'identité du dirigeant | oui si Entreprise individuelle | |
| NCC | oui | Format : 7 chiffres + 1 lettre (espace toléré), normalisé |
| RCCM, IDU | non | « dès que disponibles » |
| Portefeuille | oui | CGA ou Cabinet (M04-PTF). CGA seulement si un contrat CGA est signé (RG-PTF-08) |
| Régime | oui | Portefeuille CGA : RME ou TEE uniquement. Portefeuille Cabinet : RME, TEE, RSI ou RNI (réel normal) |
| Ancien numéro | non | Référence historique d'un client repris (CR-12) |
| Apporteur d'affaires | non | **Portefeuille CGA uniquement** : rattache le dossier au sous-portefeuille « Accessoires » (RG-PTF-06) ; champ masqué pour un dossier Cabinet |
| CDI de rattachement | oui | Liste F-REF-02 |
| Code activité | non | Nomenclature DGI quand disponible |
| Activité principale | oui | Texte |
| Siège / localisation, commune | oui | |
| N° de section et de parcelle | non | Requis par le rapport, « dès que disponible » |
| Téléphone | oui | Format ivoirien à 10 chiffres |
| WhatsApp | non | Par défaut = téléphone |
| E-mail | non | |
| Signataire du contrat (nom et qualité) | oui | |
| Effectif salarié | non | Déclenche CNPS / CMU (RG-OBL-03) |

Étape 2 — Activité et adhésion :

| Champ | Obligatoire | Règle |
|---|---|---|
| Secteur | oui | Liste F-REF-01, bouton « Proposer un secteur » |
| Chiffre d'affaires de référence | oui | Montant + type (prévisionnel pour un nouveau contribuable / exercice N-1) + année |
| Date de signature du contrat CGA (date d'adhésion) | oui pour le portefeuille CGA | Atteste l'existence du contrat (RG-PTF-08). Peut être antérieure à aujourd'hui ; la date de saisie est tracée à part. L'exemplaire signé scanné est une pièce attendue du dossier |
| Droit d'adhésion | oui pour le portefeuille CGA | Montant par défaut du CGA (25 000 F), modifiable |
| Honoraires | oui | Montant et périodicité proposés par le barème du CGA selon le régime, modifiables |
| Obligations | — | Proposées automatiquement (RG-OBL-03), à confirmer ou ajuster |

Actions : **Enregistrer comme brouillon** (sans numéro) ou **Valider l'adhésion**.

- **RG-ADH-01** Un brouillon n'a pas de numéro et apparaît dans « Dossiers à compléter ». Il peut être supprimé tant qu'aucun document n'a été émis.
- **RG-ADH-02** « Valider l'adhésion » (portefeuille CGA) exige : tous les champs obligatoires remplis, **la date de signature du contrat CGA et la confirmation « Le contrat CGA est signé par les deux parties »** (RG-PTF-08), **et** le paiement du droit d'adhésion enregistré et confirmé (F-PAY-02). Sinon, le bouton affiche la liste de ce qui manque. Un dossier Cabinet est validé sans contrat CGA ni droit d'adhésion.
- **RG-ADH-03** À la validation, le **n° CGA Connect** est attribué, identifiant chronologique unique :
  - portefeuille CGA : `AAAA-ABR-NNNN` (année de la date d'adhésion, abréviation du CGA, numéro d'ordre sur 4 chiffres) ;
  - portefeuille Cabinet : `CAB-ABR-NNNNN`.

  Les formats sont paramétrables. Chaque séquence est **unique et continue par CGA, sans remise à zéro annuelle** (ex. après 2026-DCK-0142 vient 2027-DCK-0143). Attribution atomique, sans doublon ni trou. Un client du Cabinet qui bascule dans le portefeuille CGA reçoit un numéro d'adhésion CGA et garde son numéro Cabinet dans l'historique.
- **RG-ADH-04** Les pièces justificatives ne bloquent pas la validation (RG-PJ-02).
- **RG-ADH-05 — Exclusivité d'adhésion (CR-07).** Un contribuable ne peut être inscrit dans le portefeuille CGA de deux CGA distincts.
  - Dans un même centre, un NCC n'existe qu'une fois, quel que soit le portefeuille (CGA ou Cabinet) : on bascule le dossier, on ne le duplique pas.
  - Si le NCC est inscrit au portefeuille CGA d'un autre CGA abonné (actif ou radiation en cours), **la création ou la bascule vers le portefeuille CGA est bloquée** : « Ce contribuable est adhérent d'un autre CGA. L'inscription sera possible dès que sa radiation y aura été validée. » Le nom de l'autre CGA n'est pas affiché (RG-SEC-02).
  - Le blocage est levé automatiquement dès la validation de la radiation (F-ADH-06).
  - L'inscription au portefeuille Cabinet n'est pas bloquée.
  - Un CGA non abonné n'étant pas connu de la plateforme, aucun contrôle n'est possible dans ce cas ; l'utilisateur confirme alors sur l'honneur l'absence d'adhésion ailleurs (confirmation tracée).
- **RG-ADH-06** Après validation : le contrat d'adhésion et l'attestation de l'année peuvent être produits (F-DOC-01) ; le CGA est invité à envoyer l'invitation en ligne à l'adhérent (F-ADH-09).
- CA : deux validations simultanées dans le même CGA ne peuvent jamais obtenir le même numéro.

**F-ADH-03 Dossier adhérent** · Agents CGA
- En-tête : raison sociale, numéro, statut, régime, badges d'alerte (RSI, pièces en attente, honoraires impayés).
- Onglets : **Identité** · **Obligations** (F-OBL-04) · **Honoraires et paiements** (M05) · **Documents** (émis et déposés) · **Pièces** (M04-PJ) · **Accès en ligne** · **Préoccupations** · **Historique** (journal des modifications : auteur, date, champ, ancienne et nouvelle valeur) · **Bancabilité** (si activée).
- En-tête complété : badge de portefeuille (CGA / Cabinet), ancien n° s'il existe, badge « Radiation en cours », badge « Attestation bloquée ».
- Actions : Modifier · Produire / régénérer contrat et attestation · Inviter / gérer les accès · Demander / valider la radiation · Basculer vers le portefeuille CGA (dossier Cabinet) · Basculer vers le portefeuille Cabinet (dossier CGA) · (gérant) Bloquer / débloquer l'attestation · (gérant) Annuler la radiation.

**F-ADH-04 Modification** · Gérant, expert-comptable, comptable, juriste
- **RG-ADH-06b** Tous les champs sont modifiables, avec historique. Les champs d'identité **raison sociale, NCC, régime** exigent une pièce justificative jointe à la modification.
- **RG-ADH-07** Si un champ figurant sur le contrat ou l'attestation change, la plateforme propose « Régénérer les documents » ; le CGA décide (les anciens documents restent valides tant qu'ils ne sont pas annulés).
- L'adhérent ne modifie rien lui-même : il signale un changement via une préoccupation de catégorie « Mise à jour de mes informations ».

**F-ADH-05 Changement de régime** · Gérant, expert-comptable, comptable
- Nouveau régime, date d'effet, motif. Historique daté conservé (alimente les états de surclassement du rapport).
- **RG-ADH-08** Le passage au RSI ou au RNI d'un adhérent du portefeuille CGA est autorisé (il reflète un reclassement de l'administration) mais déclenche immédiatement l'alerte « Radiation requise — reclassement au régime réel ». La radiation pour ce motif propose de **basculer le dossier dans le portefeuille Cabinet** (RG-PTF-04) plutôt que de le clore.
- **RG-ADH-09** Un adhérent du portefeuille CGA au RSI reste suivi normalement. Au-delà de **90 jours** (paramétrable) sans radiation, le gérant reçoit une **alerte** ; il décide manuellement de bloquer ou non la délivrance d'attestation (RG-DOC-10). Aucun blocage automatique (CR-04).

**F-ADH-06 Radiation** (portefeuille CGA) · Gérant, expert-comptable

Initiateurs et motifs (CR-06) :

| Initiateur | Motifs | Modalité | Pièce |
|---|---|---|---|
| **CGA / cabinet** | Changement de zone géographique du client · Impossibilité matérielle de suivi · Manquements graves · Reclassement au régime réel · Cessation d'activité · Vente ou cession du fonds · Décès · Autre | Demande introduite par le CGA auprès du SP-CGA (hors plateforme) ; la plateforme enregistre la date et la référence de la demande | Justificatif de transfert (zone, suivi) ; PV de la commission (manquements graves) ; obligatoire |
| **Contribuable** | Insatisfaction des prestations · Changement de cabinet ou de CGA · Rupture conventionnelle · Autre | Notification formelle de résiliation par le client, enregistrée dans la plateforme | Lettre de résiliation ; obligatoire |
| **SP-CGA** | Perte (retrait) d'agrément du CGA, à la suite d'un dysfonctionnement grave ou d'un manquement réglementaire | Procédure disciplinaire ; s'applique à tous les adhérents du CGA (F-ORG-07) | Décision de retrait |

- Champs : initiateur*, motif*, précision si « Autre », date d'effet*, observation*, pièce justificative (selon le tableau), référence de la demande au SP-CGA (si initiée par le CGA).
- **RG-ADH-17 — Radiation en deux temps.**
  1. **Radiation en cours** (statut `radiation_pending`) : la demande est enregistrée ; le dossier reste suivi ; le blocage d'exclusivité RG-ADH-05 reste actif.
  2. **Radiation validée** (gérant ou expert-comptable, date et référence de validation) : les effets ci-dessous s'appliquent.

  Une radiation initiée par le contribuable ou consécutive à un décès peut être validée immédiatement.
- Option de fin de radiation : **clore le dossier** ou **le basculer dans le portefeuille Cabinet** (si le client reste client du cabinet, RG-PTF-04).
- **RG-ADH-18 — Radiation toujours manuelle (D-04).** Aucune radiation n'est créée ni validée par la plateforme : ni à l'échéance d'un délai (reclassement au réel, impayés), ni par une tâche planifiée, ni en masse (perte d'agrément, F-ORG-07). Chaque radiation est **saisie** puis **validée** par un utilisateur habilité, dossier par dossier ; la plateforme se limite à alerter et à proposer. Il en va de même pour la clôture d'un dossier du portefeuille Cabinet.
- Effets à la validation (dans une même opération, tout ou rien) :
  1. statut → Radié (ou bascule dans le portefeuille Cabinet) ;
  2. ligne ajoutée à l'état des résiliations du trimestre de la date d'effet (motif et date) ;
  3. courrier de notification au CDI de rattachement produit (F-DOC-01), à remettre par le CGA ;
  4. échéances futures non échues de l'adhérent annulées ;
  5. accès en ligne de l'adhérent passés en consultation seule pour 6 mois (RG-ADH-12) ;
  6. notification à l'adhérent (plateforme + e-mail).
- **RG-ADH-10** Seules la demande et la validation sont enregistrées (pas de suivi pas à pas de la procédure contradictoire). La validation lève le blocage d'exclusivité dans les autres CGA (RG-ADH-05).

**F-ADH-07 Annulation d'une radiation** · Gérant uniquement
- **RG-ADH-11** Possible dans les 30 jours suivant l'enregistrement, avec motif ; restaure le dossier à l'identique (statut, accès, échéances) et retire la ligne de l'état des résiliations si le rapport du trimestre n'est pas encore transmis (sinon : à corriger dans une nouvelle version du rapport, signalé à l'utilisateur).

**F-ADH-08 Retour d'un ancien adhérent**
- **RG-ADH-12** Après radiation : accès de l'adhérent limité à ses propres documents (contrat, attestations, reçus) pendant 6 mois, prolongeable par le CGA ; ensuite accès suspendu. Toutes les données et pièces restent conservées **10 ans** (CR-11).
- **RG-ADH-13** Un retour ultérieur = nouvelle adhésion : nouveau dossier, nouveau numéro, nouveau contrat. L'ancien dossier reste consultable par le CGA, retrouvé via le NCC.

**F-ADH-09 Accès en ligne de l'adhérent** · Gérant (invitation), agents (consultation)
- Inviter l'accès principal (dirigeant) et des accès secondaires : nom, téléphone, e-mail facultatif. Renvoyer, désactiver.

**F-ADH-10 Changement de CGA**
- **RG-ADH-14** Juridiquement deux contrats : radiation **validée** chez l'ancien CGA, puis nouvelle adhésion chez le nouveau (RG-ADH-05).
- **RG-ADH-15** Si les deux CGA sont abonnés et que l'adhérent donne son accord écrit (pièce jointe), le nouveau CGA peut **réimporter les données d'identification** (identité, NCC, coordonnées) — jamais l'historique de gestion de l'ancien CGA.
- **RG-ADH-16** Même mécanisme pour le transfert des adhérents d'un CGA ayant perdu son agrément (F-ORG-07).

**M04-PTF — Portefeuilles (CR-01, CR-02)**

**F-PTF-01 Portefeuilles du centre** · Agents CGA (selon droits)

| Portefeuille | Contribuables | Traitement |
|---|---|---|
| **CGA** | TEE et RME ayant signé un contrat d'adhésion au CGA | Seul portefeuille repris dans le rapport trimestriel, les états réglementaires, les documents d'adhésion (contrat, attestation) et les statistiques du CNCGA |
| **Cabinet** | RSI, RNI (réel normal), et TEE / RME sans contrat CGA | Géré exclusivement par le cabinet comptable du centre : honoraires, obligations, documents, préoccupations, campagne de clôture. **Exclu automatiquement** du rapport trimestriel, des états réglementaires et des statistiques du CNCGA |
| **Accessoires** (sous-portefeuille du portefeuille CGA) | Adhérents CGA apportés par des apporteurs d'affaires externes (ex. 200 à 300 dossiers) | Adhérents CGA à part entière (contrat, rapport, attestation) ; étiquette interne « Accessoire 1, 2… » par apporteur, pour le suivi partenaire |

- **RG-PTF-01** Les **deux portefeuilles (CGA et Cabinet comptable)** existent dans chaque centre, sans activation préalable (D-01). Leur accès suit les droits des rôles (« Voir et gérer le portefeuille Cabinet »).
- **RG-PTF-02** Chaque dossier appartient à un et un seul portefeuille à un instant donné. Les listes, recherches, exports, tableaux de bord et droits distinguent les portefeuilles. Droits par défaut : le portefeuille Cabinet est visible des profils du centre, paramétrable (RG-ROLE-07).
- **RG-PTF-03** Bascule **Cabinet → CGA** :
  - n'est possible que pour un régime RME ou TEE ;
  - exige la **signature effective du contrat CGA** : date de signature + contrat signé scanné ;
  - exige le paiement du droit d'adhésion (RG-ADH-02) ;
  - est soumise à l'exclusivité (RG-ADH-05).

  Elle attribue le n° d'adhésion CGA et fait entrer le dossier dans les états « Nouveaux adhérents » du trimestre.
- **RG-PTF-04** Bascule **CGA → Cabinet** (action « Basculer vers le portefeuille Cabinet ») : comme le contrat CGA prend fin, la bascule **est** une radiation du portefeuille CGA, saisie et validée manuellement (F-ADH-06, RG-ADH-18) avec l'issue « basculer dans le portefeuille Cabinet » — par exemple pour un reclassement au régime réel ou une résiliation du contrat CGA par le client qui reste client du cabinet. Le dossier figure dans l'état des résiliations du trimestre, reçoit un n° client s'il n'en a pas et garde tout son historique.
- **RG-PTF-05** Chaque bascule est historisée (date, sens, motif, auteur, pièce).
- **RG-PTF-06** Sous-portefeuille « Accessoires » (D-06) :
  - les apporteurs d'affaires sont gérés par le centre (nom, contact, libellé « Accessoire n », taux ou conditions en texte libre) ;
  - **seul un dossier du portefeuille CGA** peut être rattaché à un apporteur : il porte alors l'étiquette « Accessoire n » ; le champ n'existe pas pour un dossier Cabinet ;
  - à la bascule d'un dossier « Accessoire » vers le portefeuille Cabinet, le rattachement à l'apporteur prend fin (il reste dans l'historique) ; à la bascule Cabinet → CGA, un apporteur peut être indiqué ;
  - l'étiquette est une **information de gestion interne** : elle n'apparaît dans aucun document officiel, rapport réglementaire, export destiné à l'administration ni statistique du CNCGA ;
  - elle n'est visible que des utilisateurs ayant le droit « Voir les apporteurs » ;
  - elle ne modifie aucune donnée fiscale du dossier.
- **RG-PTF-07** Les obligations, honoraires, documents (hors contrat et attestation CGA), préoccupations et la campagne de clôture fonctionnent de la même façon dans les deux portefeuilles.
- **RG-PTF-08 — Contrat et portefeuille CGA (D-02).** Un dossier est dans le portefeuille CGA **si et seulement si** un contrat d'adhésion le lie au CGA :
  - il n'y entre (création ou bascule) qu'avec la date de signature du contrat et la confirmation de sa signature ; l'exemplaire signé scanné est une pièce attendue, signalée « en attente » tant qu'il n'est pas déposé ;
  - la fin du contrat (radiation validée) le fait sortir du portefeuille CGA : dossier clos ou basculé dans le portefeuille Cabinet ;
  - un dossier sans contrat CGA (en cours, ou résilié) est toujours dans le portefeuille Cabinet ou clos, jamais dans le portefeuille CGA.

**M04-PJ — Pièces justificatives**

**F-PJ-01 Pièces du dossier**
- Pièces attendues : RCCM · DFE · Pièce d'identité du dirigeant · Statuts (personnes morales uniquement) · Plan de localisation · **Contrat CGA signé** (portefeuille CGA uniquement, RG-PTF-08). Formats : PDF, JPG, PNG ; 10 Mo maximum.
- **RG-PJ-01** Chaque pièce a un statut : attendue · reçue · (pour les pièces datées) expirée.
- **RG-PJ-02** Le dossier peut être validé avant réception des pièces, avec l'indicateur « pièces en attente ».
- **RG-PJ-03** Relance automatique au CGA (et à l'adhérent s'il a un accès) 30 jours après la validation si des pièces manquent, puis tous les 30 jours.

### M05 — Droit d'adhésion et honoraires

**F-PAY-01 Barème du CGA** · Gérant
- Droit d'adhésion par défaut (25 000 F). Honoraires par défaut par régime (RME, TEE) : montant et périodicité (mensuelle par défaut, trimestrielle ou annuelle).
- **RG-PAY-01** Le montant contractuel de l'adhérent prime sur le barème. Une modification du barème ne change pas les adhérents existants.

**F-PAY-02 Enregistrement d'un paiement** · Gérant, expert-comptable, comptable
- Champs : nature (droit d'adhésion / honoraires), période(s) couverte(s), montant, date, mode (espèces, chèque, virement, mobile money), référence, justificatif facultatif.
- Effet : paiement « confirmé » + **reçu numéroté** produit (F-DOC-01).
- **RG-PAY-02** Numérotation des reçus : séquence par CGA et par année, sans trou (`REC-ABR-AAAA-NNNNN`).

**F-PAY-03 Déclaration d'un paiement par l'adhérent** · Adhérent
- L'adhérent déclare un paiement (montant, date, mode, référence) avec preuve obligatoire (photo ou PDF) → statut « à confirmer ».
- Le CGA confirme (→ reçu produit) ou rejette (motif obligatoire, notifié à l'adhérent).

**F-PAY-04 Échéances d'honoraires et impayés** · Système
- Les échéances d'honoraires sont générées selon la périodicité (le 1er de la période par défaut, paramétrable par CGA).
- **RG-PAY-03** Une échéance non réglée 30 jours après sa date passe « impayée » : alerte au CGA + rappel automatique à l'adhérent (plateforme, e-mail, SMS).
- **RG-PAY-04** L'affichage « Impayé (n mois) » compte les périodes impayées.
- **RG-PAY-05** Honoraires impayés depuis plus de **3 mois** (durée paramétrable par CGA) : **alerte** au gérant dans le tableau de bord et sur la fiche. Le gérant décide **manuellement** de bloquer ou non la délivrance d'attestation (RG-DOC-10). Aucun blocage ni suspension automatique de l'accès en ligne pour impayé (CR-04).

**F-PAY-05 Factures** — une facture peut être produite pour le droit d'adhésion, une échéance d'honoraires ou une formation payante (numérotation propre au CGA). Le CNCGA produit de la même façon les factures de cotisation adressées aux CGA (M14).

**F-PAY-06 Conformité FNE des factures** · Gérant, chargé des cotisations et honoraires · CNCGA pour les cotisations (CR-14)
La facture normalisée électronique (FNE) de la DGI s'applique aux factures émises par la plateforme.
- **RG-FNE-01** Une facture n'est **remise** au client (téléchargement, envoi) qu'une fois **certifiée FNE**. Cycle : *Brouillon → En attente de certification → Certifiée* (ou *Rejetée*, avec le motif retourné). Une facture certifiée n'est plus modifiable : une correction passe par une facture d'avoir, elle-même certifiée.
- **RG-FNE-02** La facture certifiée porte les éléments exigés par la DGI : numéro et date de certification, QR code de certification, mention / logo FNE, cachet fiscal électronique. Ces éléments, renvoyés par le service de certification, sont enregistrés sur la facture et ne sont jamais saisis à la main, sauf en mode manuel (RG-FNE-03).
- **RG-FNE-03** Le **mode de certification** est paramétrable **par organisation** (écran « Facturation FNE », F-ADM-07) :
  - **API FNE de la DGI** (identifiants propres à chaque CGA, stockés chiffrés) ;
  - **via Sell Flow** (si le centre l'utilise, conditionné à son API) ;
  - **manuel** (mode par défaut au lancement) : la facture est certifiée hors plateforme, puis l'agent saisit le numéro FNE et dépose la facture certifiée (PDF) — la plateforme vérifie la présence des deux avant de la marquer « Certifiée ».
- **RG-FNE-04** En cas d'indisponibilité du service de certification, la facture reste « En attente de certification » et est renvoyée automatiquement (nombre d'essais et délai paramétrables) ; le gérant est alerté après le dernier échec. La plateforme n'émet jamais une facture non certifiée comme si elle l'était.
- **RG-FNE-05** Les **reçus** de paiement restent produits par la plateforme (RG-PAY-02). Leur éventuelle normalisation en reçu normalisé électronique (RNE) est **à confirmer** auprès de la DGI ; le mécanisme de certification est conçu pour s'y appliquer sans développement de fond.
- Toutes les opérations de certification (demande, réponse, rejet, nouvel essai) sont journalisées.

### M07 — Obligations fiscales et sociales

**F-OBL-01 Catalogue national des obligations** · CNCGA
- Dotation initiale : référentiel de DC-KNOWING (42 obligations, 7 administrations : DGI par régime, CNPS, CMU, Inspection du travail, Commerce, Industrie / PME, Commune), validé par le CNCGA, re-validé à chaque annexe fiscale (versionné).
- Chaque type : code, libellé, catégorie (Impôts / CNPS / CMU / Autres administrations), administration, plateforme (e-impôts, e-CNPS, GUDEF…), nature (déclaration, paiement, diligence), périodicité (mensuelle, trimestrielle, annuelle, ponctuelle, sur événement), règle d'échéance (ex. « le 15 du mois suivant »), conditions d'applicabilité (régime, effectif ≥ n, secteur), nature d'impôt pour le rapport (Patente, BIC, TVA, TSE, ITS, AIRSI, IGR, IRVM, IRC, TEE, impôt microentreprise, Autres).
- **RG-OBL-01** Chaque CGA peut **ajouter** des obligations locales à son usage ; il ne modifie pas le catalogue national.

**F-OBL-02 Attribution automatique** · Système puis agents CGA
- **RG-OBL-02** À l'inscription et à chaque changement de régime, d'effectif ou de secteur, la plateforme **propose** les obligations applicables ; le CGA confirme, retire ou ajoute.
- **RG-OBL-03** Un effectif salarié > 0 déclenche les obligations CNPS et CMU.

**F-OBL-03 Génération des échéances** · Système
- **RG-OBL-04** Pour chaque obligation confirmée, les échéances sont générées sur les **3 prochains mois** glissants (paramétrable), chaque nuit.
- **RG-OBL-05** Une déclaration marquée « faite » crée automatiquement l'échéance de **paiement** correspondante, avec son montant.
- **RG-OBL-06** Report au jour ouvré suivant si l'échéance tombe un samedi, un dimanche ou un jour férié (calendrier des jours fériés géré par le CNCGA).

**F-OBL-04 Obligations d'un adhérent (vue CGA)** · Agents CGA
- Quatre blocs comme la maquette : **Impôts et taxes** (récapitulatif des montants dus, déclarations à venir, historique, diligences), **CNPS**, **CMU**, **Autres administrations**.
- Pour chaque ligne : période, libellé, échéance, plateforme, montant dû (avant et après abattement pour la TEE), montant payé, reste, statut.
- Statuts : déclaration *À faire / Faite* · paiement *À payer / Partiellement payé / Payé* · diligence *Non établie / En cours / Établie*.
- **RG-OBL-07** Le statut de paiement se déduit des montants (reste = 0 ⇒ Payé ; 0 < payé < dû ⇒ Partiellement payé).
- **RG-OBL-08** Le CGA est le référent des montants dus. Le récapitulatif « Total dû » = somme des restes à payer des échéances échues et à venir affichées.

**F-OBL-05 Mes obligations (vue adhérent)** · Adhérent
- Mêmes blocs en lecture.
- **RG-OBL-09** L'adhérent peut **déclarer** qu'une déclaration est faite ou qu'un paiement est effectué (montant, date, preuve facultative) : la ligne passe « déclaré par l'adhérent — à confirmer », visible des deux ; le CGA confirme ou corrige. Le CGA a le dernier mot.

**F-OBL-06 Planning des échéances du portefeuille** · Agents CGA
- Échéances regroupées par date et par obligation (« 15/11 — CNPS — 34 adhérents ») avec statut agrégé (nombre faits / à faire), filtres (période, obligation, statut), accès au détail des adhérents concernés, bouton « Envoyer un rappel » (F-COM-03).
- Indicateur « Échéances sous 15 jours ».

**F-OBL-07 Obligations du CGA lui-même** · Gérant, expert-comptable
- Liste simple : libellé (impôts du CGA, CNPS, CMU, renouvellement d'agrément, SMT…), échéance, montant, statut, rappel.

**F-OBL-08 Campagne de clôture (SMT et états financiers)** · Gérant, expert-comptable, comptables (CR-10)
- Chaque année, une campagne de clôture de l'exercice N est ouverte pour tous les dossiers actifs (portefeuilles CGA et Cabinet), avec le calendrier interne par défaut :

| Étape | Date par défaut | Contenu |
|---|---|---|
| 1. Collecte des pièces | 30 janvier N+1 | Pièces comptables de l'exercice reçues |
| 2. Régularisation | 28 février N+1 | Écritures et pièces manquantes régularisées |
| 3. Finalisation | 31 mars N+1 | SMT ou états financiers établis et validés avec le client |
| 4. Dépôt légal | **30 mai N+1** | Dépôt effectué (date, référence, accusé) |

- **RG-OBL-10** Les dates des étapes sont **paramétrables** (réseau par défaut, ajustables par CGA ; la date de dépôt légal est réglée par le CNCGA). Chaque dossier suit les étapes avec un statut (*à faire / fait*, date, auteur, pièce éventuelle).
- **RG-OBL-11** Rappels automatiques : au client pour les pièces (J-15 et J-5 avant l'étape 1, puis chaque semaine en retard, paramétrables) ; aux agents CGA pour les dossiers en retard sur une étape. Tableau de suivi : nombre de dossiers par étape, retards, filtres par portefeuille et par agent.
- **RG-OBL-12** Les nombres de SMT et de bilans établis et transmis du rapport trimestriel (section 4 de F-RPT-02) sont **proposés automatiquement** à partir de la campagne (portefeuille CGA uniquement), modifiables avant génération.

### M08 — Documents officiels et vérification

**F-DOC-01 Production des documents** · selon la matrice

| Document | Déclencheur | Signataire | Numérotation |
|---|---|---|---|
| Contrat d'adhésion | Adhésion validée, ou régénération | Gérant | N° d'adhésion |
| Attestation d'adhésion | Campagne annuelle ou à la demande | Gérant | `ATT-ABR-AAAA-NNNNN` |
| Reçu (droit d'adhésion, honoraires, formation payante) | Paiement confirmé | Gérant | `REC-ABR-AAAA-NNNNN` |
| Facture | À la demande | Gérant | `FAC-ABR-AAAA-NNNNN` |
| Courrier de notification de radiation au CDI | Radiation | Gérant | `NOT-ABR-AAAA-NNNNN` |
| Rapport trimestriel (Excel officiel, Word, PDF) | Génération du rapport | Gérant et / ou expert-comptable | `RPT-ABR-AAAA-Tn-Vn` |
| Bordereau de transmission du rapport | Transmission | Gérant ou expert-comptable | lié au rapport |
| Reçu de cotisation CNCGA | Paiement confirmé par le CNCGA | Présidente du CNCGA | `CNC-AAAA-NNNNN` |
| Attestation de formation | Présence confirmée | Organisateur | `FOR-…` |

- **RG-DOC-01** Les modèles sont ceux du réseau, aux formats normés par la DGI : mentions obligatoires intégralement conservées. Personnalisation limitée à l'en-tête, au logo, au signataire, à l'image de signature et au cachet du CGA. Toute évolution de fond d'un modèle est soumise au SP-CGA avant déploiement.
- **RG-DOC-02** Signature : image de la signature et du cachet du signataire, **sans la mention « signature électronique »**, accompagnée du cachet de vérification (QR).
- **RG-DOC-03** Chaque document émis a un numéro, une date, une version, un code de vérification aléatoire et une empreinte numérique ; il est conservé (section 7).
- **RG-DOC-04** Formats : le CGA obtient PDF et Word ; l'adhérent obtient le **PDF seulement**.
- **RG-DOC-05** Production non bloquante : l'utilisateur est prévenu quand le document est prêt (quelques secondes).

**F-DOC-02 Attestation d'adhésion**
- **RG-DOC-06** Une attestation par année civile (campagne annuelle lancée par le CGA pour tous les adhérents éligibles) + réédition (duplicata) à la demande. Validité : 12 mois à compter de l'émission.
- **RG-DOC-07** Interdite pour un adhérent radié ou en radiation en cours, et pour un dossier du portefeuille Cabinet (l'attestation d'adhésion est propre au portefeuille CGA). Interdite aussi si un **blocage manuel** est actif (RG-DOC-10) ; le motif est affiché.
- **RG-DOC-10** Blocage manuel de l'attestation · Gérant : sur alerte (honoraires impayés RG-PAY-05, RSI non radié RG-ADH-09) ou de sa propre initiative, le gérant peut **bloquer** la délivrance d'attestation d'un adhérent, avec motif obligatoire, puis la **débloquer** (motif). Blocage et déblocage sont historisés et visibles sur la fiche. Jamais de blocage automatique (CR-04).

**F-DOC-03 Annulation d'un document** · Gérant, expert-comptable
- Motif obligatoire, tracé. Le document reste consultable, marqué « ANNULÉ le jj/mm/aaaa ».
- **RG-DOC-08** Une radiation annule automatiquement l'attestation en cours de validité.

**F-DOC-04 Page publique de vérification** · Public (banques, administrations), sans compte
- Accès par le QR code (lien contenant un code aléatoire, sans aucune donnée lisible).
- Affiche uniquement : **document valide ou annulé (avec date)**, **type et date du document**, **CGA émetteur**, **nom de l'adhérent**, **numéro d'adhésion**.
- **RG-DOC-09** Chaque consultation est journalisée (date, adresse IP tronquée). Limitation du nombre de consultations par minute.

**F-DOC-05 Dépôt de documents par le CGA** · Agents CGA
- Déposer dans l'espace de l'adhérent un document (états financiers visés, attestations diverses) : titre, catégorie, fichier PDF → notification « nouveau document disponible ».

**F-DOC-06 Demande de document par l'adhérent** · Adhérent
- Bouton « Demander » → crée une préoccupation de catégorie « Demande de document » (M10).

### M09 — Rapport trimestriel

**F-RPT-01 Fiche permanente du CGA** · Gérant, expert-comptable
- Saisie une fois, mise à jour si besoin, **revue demandée avant chaque génération** :
  - Dirigeants : nom et prénoms, nationalité, profession, diplômes, fonction dans le CGA, date de prise de service, pratique professionnelle (structure, fonction, années), impôts acquittés au titre du trimestre (ITS, IGR, CNPS) — ces derniers saisis à chaque rapport.
  - Salariés permanents (avec statut CNPS déclaré / non déclaré) et personnels occasionnels.
  - Moyens matériels : locaux, salles de formation, mobilier, matériel informatique, logiciels, vidéoprojecteurs, véhicules (quantités), besoins.
  - Présentation et historique du CGA, chiffre d'affaires N-1 du CGA, Direction régionale, SAID.

**F-RPT-02 Préparation du rapport** · Gérant, expert-comptable, comptable, secrétaire
- Choix de l'année et du trimestre (liste, pas de texte libre). Un seul rapport par CGA, année et trimestre.
- Sections avec taux de complétude :
  1. Fiche technique du CGA (depuis F-RPT-01)
  2. Capital humain (depuis F-RPT-01 + impôts du trimestre)
  3. Ressources matérielles (depuis F-RPT-01)
  4. Gestion des adhérents : formations dispensées, assistance comptable (nature, bénéficiaires, fréquence), outils mis à disposition, prévention financière et fiscale, nombre de SMT établis et transmis, nombre de bilans établis et transmis, réunions et activités de promotion (textes et nombres saisis)
  5. États du portefeuille (calculés, voir ci-dessous)
  6. Difficultés (internes, avec les partenaires), suggestions, prévisions du trimestre suivant (textes)
- **RG-RPT-01** Principe « zéro ressaisie » : les états du portefeuille sont calculés à partir des dossiers adhérents, de l'historique des régimes, des radiations et des paiements confirmés des obligations.
- **RG-RPT-02** Complétude d'une section = champs requis renseignés / champs requis. Le rapport est générable quand toutes les sections sont à 100 % (les textes peuvent valoir « Néant »).

États calculés (à la date de fin du trimestre, regroupés **par CDI**) :

| Feuille du classeur | Contenu | Règle de calcul |
|---|---|---|
| Suivi TEE | Adhérents TEE actifs : identité, NCC, activité, localisation, n° et date d'adhésion, section et parcelle, CA de référence ; TEE exigible avant / après abattement, acquittée (montant, nombre de mois), reste à recouvrer, taux de recouvrement — annuel et trimestriel | Échéances et paiements des obligations TEE |
| Suivi RME | Adhérents RME actifs + paiements du trimestre par nature : Patente, BIC, TVA, TSE, ITS, AIRSI, IGR, IRVM, IRC, Autres (FDFP), Total | Paiements confirmés du trimestre, par nature d'impôt |
| Nouveaux TEE / Nouveaux RME | Adhésions dont la date d'adhésion est dans le trimestre (TEE : avec date d'immatriculation) | Date d'adhésion |
| Surclassement TEE → RME | Adhérents passés de TEE à RME dans le trimestre : CA N-3 à N-1, cotisations annuelles N-3 à N-1, cotisation N, motif | Historique des régimes + historique des CA |
| Surclassement RME → RSI | Idem RME → RSI : CA N-3 à N-1, motif | Historique des régimes |
| Résiliations TEE / RME | Radiations dont la date d'effet est dans le trimestre : identité, CA, reste à recouvrer (TEE : exigible / acquitté ; RME : par nature), date et motif | Radiations (hors annulées) |
| TEE augmentée / RME augmentée | Adhérents dont la cotisation a augmenté | **Règle paramétrable** (RG-RPT-08) |
| Résumé | Totaux par CDI : adhérents TEE, RME, résiliés, nouveaux | Agrégats des feuilles |

- **RG-RPT-03** Les numéros historiques d'adhésion (ex. `DCK-CGA2019-A001`) sont affichés tels quels.
- **RG-RPT-08** Règle « cotisation augmentée » paramétrable par le CNCGA (valable pour tout le réseau), sans développement :
  - **base de comparaison** : montant annuel dû de l'année N comparé à N-1 (défaut) · montant dû du trimestre comparé au même trimestre de N-1 · chiffre d'affaires de référence N comparé à N-1 ;
  - **seuil** : augmentation minimale en % (défaut 0 %, c'est-à-dire toute hausse) ;
  - **montant comparé** : après abattement (défaut) ou avant abattement.

  Le paramétrage en vigueur est enregistré dans chaque version de rapport générée, pour pouvoir la reproduire.

**F-RPT-03 Génération** · mêmes rôles (CR-08)
- Produit : le **rapport modernisé** en PDF et en Word — mise en page visuelle, **graphiques** (répartition du portefeuille par régime et par CDI, évolution trimestrielle des adhérents, nouveaux / résiliés, recouvrement TEE, paiements RME par nature, avancement des SMT et bilans), tableaux réglementaires et **analyse qualitative** (synthèse, faits marquants, difficultés, perspectives).
- **RG-RPT-09** Le rapport modernisé conserve **tous les agrégats réglementaires** des états du portefeuille (tableau ci-dessus) ; seule la présentation change. Les textes d'analyse qualitative sont saisis par le CGA ; la plateforme propose une **synthèse chiffrée pré-rédigée** (variations par rapport au trimestre précédent) que le CGA peut modifier.
- **RG-RPT-10** Le **classeur Excel de 14 feuilles** reste disponible comme **export de compatibilité** (paramètre réseau « export classeur historique », activé par défaut tant que le SP-CGA n'a pas confirmé l'acceptation du seul rapport modernisé).
- **RG-RPT-11** Le rapport ne porte que sur le **portefeuille CGA** (RG-SEC-08).
- **RG-RPT-12** Lorsque le centre est connecté à Compta Flow (F-INT-03), le chiffre d'affaires et les paiements d'impôts des adhérents sont alimentés automatiquement ; en cas d'écart avec une saisie de la plateforme, **la donnée Compta Flow fait foi** (RG-DATA-06). Sinon, les données viennent du suivi des obligations et des imports (F-IMP-03).
- Prévisualisation à l'écran avant génération.

**F-RPT-04 Transmission** · Gérant ou expert-comptable
- Le CGA envoie lui-même le rapport au SP-CGA (la plateforme ne transmet rien directement, CR-05), puis enregistre la transmission dans la plateforme : date, mode (dépôt physique, e-mail, autre), référence. Un bordereau de transmission est produit.
- **RG-RPT-04** La transmission rend visible au CNCGA : l'état (transmis / en retard / non transmis), la date et **le seul tableau de synthèse agrégé** (feuille Résumé). Jamais le contenu nominatif.
- Statuts : Brouillon → Généré → Transmis → Accusé reçu.

**F-RPT-05 Accusé de réception** · Gérant ou expert-comptable
- Date, référence de l'accusé SP-CGA et fichier scanné.

**F-RPT-06 Correction après envoi**
- **RG-RPT-05** Un rapport transmis peut être corrigé : nouvelle version (V2, V3…) avec motif ; chaque version transmise est horodatée et conservée.

**F-RPT-07 Échéances du rapport**
- **RG-RPT-06** Date de référence (J) : fin du mois qui suit le trimestre (30 avril, 31 juillet, 31 octobre, 31 janvier), paramétrable par le CNCGA. Relances au gérant et à l'expert-comptable à **J-15** (plateforme, e-mail, SMS), **J-5** (plateforme, e-mail, SMS) et **J+5** si non transmis (plateforme, e-mail, SMS). Décalages paramétrables (CR-09).
- **RG-RPT-07** Le lendemain de J, un rapport non transmis passe « en retard » (alerte au CGA). À **J+15**, échéance réglementaire stricte (paramétrable) : le rapport passe « hors délai réglementaire » et apparaît dans les alertes du CNCGA.

### M10 — Préoccupations

**F-PRE-01 Soumettre une préoccupation** · Adhérent → son CGA ; Gérant / expert-comptable → CNCGA
- Champs : catégorie*, objet*, message*, pièces jointes (facultatif).
- Catégories : Fiscalité · Comptabilité · Juridique · Social (CNPS / CMU) · Formation · Administratif · Plateformes publiques · Demande de document · Mise à jour de mes informations · Autre.

**F-PRE-02 Traiter une préoccupation** · Agents CGA (adhérents) ; CNCGA (CGA)
- Fil de messages ; **notes internes** invisibles du demandeur ; changement de statut.
- Statuts : Ouverte → En examen → Traitée ou Rejetée (motif). Le demandeur peut **rouvrir** une demande traitée ou rejetée (retour à « Ouverte »).
- **RG-PRE-01** Délais visés (indicatifs, non bloquants) : 7 jours pour un CGA, 15 jours pour le CNCGA. Affichage du délai écoulé.
- **RG-PRE-02** Une demande sans réponse depuis 15 jours déclenche une alerte interne au CGA. Aucune remontée automatique au CNCGA.

**F-PRE-03 Transfert au CNCGA** · Gérant, expert-comptable
- Transférer une demande d'adhérent que le CGA ne peut pas régler : crée une préoccupation CGA → CNCGA liée, avec **copie de l'échange d'origine** ; la demande d'origine passe « En examen » avec la mention « Transmise au CNCGA ».

**F-PRE-04 Tableau des délais** · Gérant (son CGA), CNCGA (réseau, agrégé)
- **RG-PRE-03** Le CNCGA voit uniquement des chiffres sur les demandes adhérents → CGA (nombre, catégories, délais moyens, taux de traitement), jamais leur contenu.

### M11 — Formations et séminaires

**F-FOR-01 Créer une formation** · CNCGA (pour le réseau) ; CGA (pour ses adhérents et agents)
- Champs : titre*, description, date(s) et horaires*, format (présentiel / en ligne / atelier), lieu ou lien en ligne, formateur, nombre de places, prix (0 = gratuite), validation des inscriptions par l'organisateur (oui / non), supports, publics cibles*.
- Publics : Responsables CGA · Tous les agents CGA · Comptables CGA · Tous les adhérents · Adhérents d'un régime (RME / TEE) · Nouveaux adhérents (adhésion depuis moins de 6 mois).
- **RG-FOR-01** Publication directe, sans validation croisée. Un CGA ne cible que ses propres adhérents et agents.
- **RG-FOR-02** Une formation du CNCGA ouverte aux adhérents est visible directement par les adhérents concernés ; leur CGA est notifié.

**F-FOR-02 S'inscrire / inscrire** · Personne ciblée ; CGA pour ses agents et adhérents
- **RG-FOR-03** Au-delà des places : liste d'attente, avec promotion automatique en cas de désistement (notifiée).
- **RG-FOR-04** Désinscription possible jusqu'à J-2.
- **RG-FOR-05** Si l'option est activée, l'inscription est « en attente de validation » jusqu'à la validation de l'organisateur.

**F-FOR-03 Paiement des formations payantes**
- **RG-FOR-06** Statut de paiement suivi (à payer / payé), encaissement hors plateforme ; un reçu est produit quand le paiement est enregistré.

**F-FOR-04 Après la session** · Organisateur
- Liste de présence (émargement : présent / absent), attestation de participation générée pour les présents, évaluation à chaud (questionnaire court : note 1–5 et commentaire), résultats agrégés.

### M12 — Annonces et communication

**F-COM-01 Annonces du CNCGA** · Président, gestionnaire, resp. formations
- Titre, texte, pièce jointe, cible : tous les CGA · certains CGA choisis · tous les adhérents du réseau. Canaux : plateforme (toujours) + e-mail (option).

**F-COM-02 Annonces du CGA** · Gérant, expert-comptable
- Vers ses adhérents (tous ou filtrés par régime / secteur). Affichées dans « Message de mon CGA ».

**F-COM-03 Rappels groupés** · Gérant, expert-comptable, comptable
- Destinataires (sélection ou filtre), canaux (plateforme, e-mail, SMS, WhatsApp), message libre ou modèle.
- **RG-COM-01** Au-delà de **50 destinataires**, l'envoi doit être validé par le gérant.
- **RG-COM-02** Les SMS sont décomptés du **quota du CGA** (proposition : 200 SMS / mois inclus, puis packs) ; WhatsApp facturé à la conversation. Le CNCGA paie ses propres campagnes. [Chiffrage à faire par l'équipe projet]
- Historique des envois : date, objet, canal, nombre de destinataires, statut de délivrance.

### M13 — Notifications et rappels automatiques

| Événement | Destinataire | Plateforme | E-mail | SMS | WhatsApp |
|---|---|---|---|---|---|
| Invitation (accès ouvert) | Nouvel utilisateur | — | ✔ | ✔ | — |
| Nouveau document disponible | Adhérent | ✔ | ✔ | — | — |
| Échéance fiscale ou sociale à J-7 puis J-1 | Adhérent | ✔ | ✔ | ✔ | ✔ |
| Échéance dépassée non réglée | Adhérent et CGA | ✔ | ✔ | — | — |
| Honoraires impayés | Adhérent | ✔ | ✔ | ✔ | — |
| Pièces en attente (J+30, puis tous les 30 j) | CGA (+ adhérent) | ✔ | ✔ | — | — |
| Nouvelle préoccupation | CGA ou CNCGA | ✔ | ✔ | — | — |
| Réponse / changement de statut d'une préoccupation | Demandeur | ✔ | ✔ | — | — |
| Préoccupation sans réponse depuis 15 jours | CGA | ✔ | — | — | — |
| Nouvelle formation publiée | Public ciblé (+ CGA si formation CNCGA) | ✔ | ✔ | — | — |
| Inscription confirmée / promotion de liste d'attente / rappel J-1 | Inscrit | ✔ | ✔ | ✔ | — |
| Nouvelle annonce | Destinataires | ✔ | ✔ (option) | — | — |
| Relance de cotisation CNCGA (J+30, J+60, J+90 après échéance) | Gérant du CGA | ✔ | ✔ | — | — |
| Paiement de cotisation confirmé / rejeté | Gérant du CGA | ✔ | ✔ | — | — |
| Rapport : J-15, J-5, puis J+5 si non transmis | Gérant + expert-comptable | ✔ | ✔ | ✔ | — |
| Rapport hors délai réglementaire (J+15) | Gérant + CNCGA | ✔ | ✔ | — | — |
| Campagne de clôture : rappel de pièces et d'étape | Adhérent (pièces) · agents CGA (retards) | ✔ | ✔ | ✔ | ✔ |
| Radiation demandée / validée | Adhérent · gérant | ✔ | ✔ | ✔ | — |
| Facture certifiée FNE disponible / certification rejetée | Client · gérant | ✔ | ✔ | — | — |
| Synchronisation Compta Flow en échec | Gérant | ✔ | ✔ | — | — |
| Rapport transmis | CNCGA | ✔ | — | — | — |
| Radiation enregistrée | Adhérent | ✔ | ✔ | — | — |
| Paiement déclaré par l'adhérent | CGA | ✔ | — | — | — |
| Paiement confirmé / rejeté | Adhérent | ✔ | ✔ | — | — |
| Échéance d'agrément à 6 et 3 mois | Gérant + CNCGA | ✔ | ✔ | — | — |
| Adhérent RSI ou RNI du portefeuille CGA non radié à J+60 et J+90 | CGA | ✔ | ✔ | — | — |
| Honoraires impayés depuis plus de 3 mois (RG-PAY-05) | Gérant | ✔ | ✔ | — | — |

- **RG-NOT-01** Les notifications dans la plateforme et les e-mails sont illimités.
- **RG-NOT-02** SMS et WhatsApp ne sont envoyés qu'entre 7 h et 20 h, sauf codes de connexion.
- **RG-NOT-03** Si l'adhérent n'a pas d'e-mail, la notification « e-mail » est remplacée par un SMS pour les événements contractuels (radiation, échéance dépassée).
- **RG-NOT-05** Les identifiants des comptes SMS, WhatsApp et e-mail sont **paramétrables dans l'application** par l'administrateur de la plateforme (F-ADM-06) :
  - ils sont stockés chiffrés et ne sont jamais réaffichés en clair (seuls les 4 derniers caractères apparaissent) ;
  - toute modification est journalisée ;
  - tant qu'un canal n'est pas configuré et actif, ses notifications sont remplacées par la plateforme et l'e-mail, sans erreur pour l'utilisateur.
- **RG-NOT-04** L'adhérent peut refuser SMS et WhatsApp **non essentiels** (formations, annonces, rappels groupés). Les notifications contractuelles (échéances, radiation) restent servies au minimum par e-mail ou SMS et dans la plateforme. Le consentement est recueilli à l'activation (loi n° 2013-450).

### M14 — Cotisation CNCGA

**F-COT-01 Appel de cotisation annuel** · CNCGA
- Chaque 1er janvier, une cotisation de l'année est créée pour **chaque CGA du registre** (abonné ou non) au montant de l'année (750 000 F, révisable chaque année).
- **RG-COT-01** Modalité par défaut : versements libres avant le **30 juin** (paramétrable : paiement unique, trimestriel ou libre avec date butoir). [À confirmer bureau du CNCGA]
- **RG-COT-02** CGA arrivé en cours d'année : prorata **temporis semestriel** (arrivée au 2e semestre = 50 %). Confirmé (CR-03).
- **RG-COT-03** Cotisation (statutaire) et abonnement (contractuel) sont strictement distincts : comptes et reçus séparés.

**F-COT-02 Suivi des cotisations** · CNCGA
- Tableau : CGA, montant dû, réglé, reste, statut (À jour / Partielle / Impayée / En attente de confirmation), dernière relance, action.
- **RG-COT-04** Statut : reste = 0 ⇒ À jour ; 0 < réglé < dû ⇒ Partielle ; réglé = 0 après la date butoir ⇒ Impayée.

**F-COT-03 Déclaration de paiement par le CGA** · Gérant
- Montant, date, mode, référence, preuve obligatoire → « à confirmer ». Le CNCGA confirme (reçu numéroté produit) ou rejette (motif).

**F-COT-04 Saisie directe par le CNCGA** — paiement reçu (chèque, espèces, virement constaté) → reçu.

**F-COT-05 Relances**
- **RG-COT-05** Relances automatiques à J+30, J+60 et J+90 après la date butoir (plateforme + e-mail au gérant) ; relance manuelle à tout moment.
- **RG-COT-06** À J+90, la Présidente est alertée qu'elle **peut** suspendre le CGA ; la suspension n'est jamais automatique (confirmé, CR-04). Il en va de même pour l'abonnement à la plateforme : aucune coupure automatique.

**F-COT-06 Vue CGA** · Gérant, expert-comptable
- Sa cotisation de l'année, ses paiements, reçus téléchargeables, bouton « Déclarer un paiement ».

### M15 — Tableaux de bord et statistiques

**F-DSH-01 Tableau de bord CNCGA** — CGA du réseau (abonnés / total), cotisations recouvrées de la période et % de l'attendu, préoccupations ouvertes (CGA → CNCGA), adhérents du réseau (abonnés, avec variation), graphique mensuel des cotisations, alertes (rapports en retard, CGA impayés, agréments à échéance, formations presque complètes).

**F-DSH-02 Tableau de bord CGA** — adhérents actifs, dossiers à compléter, honoraires (dû / encaissé / impayé de la période), échéances sous 15 jours, avancement du rapport du trimestre, alertes (RSI actifs, honoraires impayés, pièces en attente, SMT, préoccupations sans réponse).

**F-DSH-03 Tableau de bord adhérent** — prochaine échéance, honoraires (à jour / impayé, montant), impôts dus, CNPS + CMU dus, échéances du mois, dernier message de son CGA, documents récents.

**F-DSH-04 Sélecteur de période** — Jour / Mois (défaut) / Année / Période personnalisée. Année = année civile. Chaque indicateur de période affiche sa variation par rapport à la même période de N-1.

**F-STA-01 Statistiques nationales** · CNCGA

| Indicateur | Formule |
|---|---|
| Recettes fiscales du réseau | Somme des paiements d'impôts et cotisations **confirmés** dans le suivi des obligations des adhérents, par nature et par période |
| Chiffre d'affaires des adhérents | Somme des CA de l'année : donnée Compta Flow si disponible (RG-DATA-06), sinon CA déclarés (fiche adhérent, imports) |
| Contribution au budget de l'État | Recettes fiscales du réseau ÷ recettes fiscales intérieures de l'année (F-REF-04) |
| Contribution au PIB | CA des adhérents ÷ PIB nominal de l'année (F-REF-04) |

Plus : recettes par CGA, adhérents par secteur, par régime, par DR et par commune ; carte économique par Direction régionale (bulles proportionnelles au CA, nombre de CGA, nombre d'adhérents), détail par commune.
- **RG-STA-01** Mention systématique : « Sur la base de x CGA abonnés sur 111 ».
- **RG-STA-02** Statistiques institutionnelles (cotisations, transmission des rapports) : sur les 111 CGA. Statistiques économiques : sur les CGA abonnés uniquement.
- **RG-STA-05** Toutes les statistiques économiques ne portent que sur le **portefeuille CGA** des centres (RG-SEC-08).
- **RG-STA-03** Indicateurs macro affichés « non disponible » tant que les références nationales de l'année ne sont pas saisies.

**F-STA-02 Position du CGA dans le réseau** · Gérant, expert-comptable
- Ses indicateurs comparés aux **moyennes anonymisées** du réseau.
- **RG-STA-04** Une moyenne n'est affichée que si elle porte sur au moins 5 CGA (sinon « données insuffisantes »), pour empêcher d'identifier un autre CGA.

**F-STA-03 Plaquette annuelle** · CNCGA — export PDF des statistiques de l'année. Pas de version publique en ligne.

### M16 — Gestion interne du CGA
- **F-INT-01 Fiche permanente** : F-RPT-01.
- **F-INT-02 Obligations du CGA** : F-OBL-07.
- **F-INT-03 Connecteur Compta Flow (lecture)** · Gérant (paramétrage), système (synchronisation) — CR-08, CR-15. Lot dédié, conditionné à la disponibilité de l'API Compta Flow.
  - Le gérant active la connexion de son centre (identifiants propres au centre, stockés chiffrés, bouton « Tester la connexion »).
  - Données lues, par dossier rapproché par NCC : chiffre d'affaires (annuel et mensuel), paiements d'impôts et cotisations sociales par nature et par période.
  - Synchronisation planifiée (fréquence paramétrable, par défaut chaque nuit) et à la demande ; rapport de synchronisation (lus, rapprochés, non rapprochés, écarts).
  - **RG-DATA-06** En cas d'écart entre une donnée Compta Flow et une donnée saisie ou importée, **Compta Flow fait foi** : la valeur Flow est retenue, l'écart est signalé et tracé (ancienne valeur, nouvelle valeur, source). Une donnée issue de Flow n'est pas modifiable à la main (elle se corrige dans Flow).
  - **RG-DATA-07** Les structures sans Compta Flow utilisent l'import Excel (F-IMP-03), qui reste toujours disponible.
- Les onglets « Prestations », « Charges », « Trésorerie » et « Personnel (RH Flow) » de la maquette dépendent des applications Flow : **évolution**.

### M17 — Imports et exports

**F-IMP-01 Import du registre des CGA** · `platform_admin`, CNCGA — fichier Excel modèle (dénomination, sigle, agrément, date d'agrément, ville, DR, téléphone, e-mail). Rapport d'anomalies ligne par ligne.

**F-IMP-02 Import d'un portefeuille existant** · Gérant (avec assistance de l'équipe)
- Fichier Excel modèle (une ligne par adhérent, colonnes = champs F-ADH-02 dont portefeuille, régime, date de signature du contrat CGA et apporteur (lignes CGA uniquement ; une ligne Cabinet avec apporteur est rejetée) + ancien n° d'adhésion + date d'adhésion + statut + montant des honoraires).
- **RG-IMP-01** **Double numérotation** (CR-12) : chaque dossier repris garde son **ancien numéro** tel quel (champ « Ancien n° », affiché et recherchable) **et reçoit un numéro CGA Connect** attribué dans l'ordre chronologique des dates d'adhésion. Les nouveaux entrants n'ont que le numéro CGA Connect, chronologique et unique (RG-ADH-03). Le gérant peut fixer le numéro de départ de la séquence.
- **RG-IMP-02** Une ligne incomplète est importée en **brouillon** avec la liste des champs manquants ; une ligne invalide (NCC mal formé, doublon) est rejetée avec motif. Rapport d'import téléchargeable.
- **RG-IMP-03** Les adhérents importés « actifs » sont réputés avoir acquitté leur droit d'adhésion.

**F-IMP-03 Import de données de paiement / CA** · Agents CGA — fichier Excel modèle pour l'historique (CA par année, paiements d'impôts par nature et par période) : reprise d'historique pour les surclassements et le rapport.

**F-EXP-01 Exports Excel** — Adhérents, cotisations, préoccupations, inscrits aux formations, échéances, statistiques ; horodatés, filtrés par période, limités au périmètre (RG-SEC-06).

### M18 — Bancabilité (activable par CGA) · [Périmètre à confirmer par le bureau du CNCGA]
- **RG-BNK-00** Module désactivé par défaut ; activé par CGA par le CNCGA (ou `platform_admin`).

**F-BNK-01 Consentement** · Adhérent principal, ou CGA avec accord écrit déposé
- **RG-BNK-01** Aucun dossier bancaire ne peut être constitué sans consentement enregistré (date, auteur, pièce si recueilli hors ligne). Retrait possible.

**F-BNK-02 Pièces du dossier bancaire** — 13 pièces : RCCM et statuts · Déclaration fiscale d'existence (NCC, IDU) · Attestation d'adhésion de l'exercice (automatique) · États financiers N-1 visés · États financiers N-2 visés · Attestation de dépôt GUDEF · Attestation de régularité fiscale · Attestation CNPS · Fiche de conformité (niveau déclaré par le CGA) · CA mensualisé 12 mois · Compte d'exploitation prévisionnel · Plan de trésorerie prévisionnel · Relevés bancaires 6 mois.
- **RG-BNK-02** Expirations suivies : attestation de régularité fiscale et attestation CNPS (3 mois par défaut), états financiers (millésime). La liste des pièces, leur caractère obligatoire et leur durée de validité sont **paramétrables** par le CNCGA (F-BNK-08). État du dossier : complet · incomplet · pièces expirées.

**F-BNK-03 Score** — sur 100, **entièrement paramétrable** (F-BNK-08). Critères et poids par défaut (CR-17, à affiner avec les banques) :

| Critère | Poids | Mesure par défaut [hypothèse, paramétrable] |
|---|---|---|
| Solvabilité / poids économique | 60 % | Moyenne de deux sous-critères : niveau de chiffre d'affaires (barème par tranches) et taux de recouvrement des impôts dus sur 12 mois |
| Conformité | 15 % | Pièces du dossier bancaire disponibles et valides (50 %) + niveau de conformité déclaré par le CGA (50 %, grille conforme 100 / partiellement 50 / non conforme 0) |
| Assiduité / réactivité | 15 % | Obligations déclarées dans les délais sur 12 mois, honoraires à jour, pièces de clôture remises à temps (F-OBL-08) |
| Avis bancaire | 10 % | Avis saisi par le CGA après échange avec la banque : favorable 100 / réservé 50 / défavorable 0 / sans avis 50 |

Notes : A ≥ 85 · B ≥ 65 · C sinon. Score historisé avec le paramétrage utilisé. Le terme « anxiété » du compte rendu est interprété comme « assiduité » [à confirmer].

**F-BNK-08 Paramétrage de la bancabilité** · Présidente et gestionnaire CNCGA (réseau)
- **RG-BNK-03** Paramètres modifiables : liste des critères et sous-critères, poids de chaque critère (la somme doit faire 100 %, contrôle à l'enregistrement) ; seuils des notes A et B ; ancienneté donnant 100 % et note intermédiaire ; barème de la grille de conformité ; liste des pièces (ajout, retrait, obligatoire ou non, durée de validité en mois).
- **RG-BNK-04** Le paramétrage est commun à tout le réseau (scores comparables entre CGA). Une modification ne recalcule pas les scores passés ; un bouton « Recalculer tous les scores » applique le nouveau paramétrage, et chaque score garde la version du paramétrage utilisée.
- **RG-BNK-05** Toute modification est journalisée ; « Revenir aux valeurs par défaut » est disponible.

**F-BNK-04 Banques partenaires** — banques sous convention réseau (CNCGA) + partenaires locaux (CGA) : nom, statut de convention, concours proposés.

**F-BNK-05 Demandes de financement** — banque, objet, montant, date, statut (en préparation / déposée / accordée / rejetée) et motif.

**F-BNK-06 Dossier PDF** — produit avec cachet de vérification, remis par le CGA à la banque.

**F-BNK-07 Vue adhérent « Ma bancabilité »** — score, pièces, demandes ; bouton de consentement.

### M19 — Administration de la plateforme
- **F-ADM-01** Créer l'organisation CNCGA et son premier utilisateur ; créer des CGA ; activer des modules par CGA.
- **F-ADM-02** Gérer les modèles de documents : déposer une nouvelle version (DOCX ou classeur XLSX), vérifier les variables, prévisualiser avec des données de test, activer — après validation du CNCGA (RG-DATA-03).
- **F-ADM-03** Consulter les journaux d'audit ; produire le journal des accès techniques pour un CGA (RG-SEC-04).
- **F-ADM-04** Calendrier des jours fériés, paramètres techniques (quotas SMS par défaut, plage horaire d'envoi).
- **F-ADM-06 Paramètres d'envoi** (RG-NOT-05) — écran de configuration des comptes d'envoi, **sans intervention technique** :
  - **SMS** : fournisseur (liste), URL de l'API, identifiant / clé d'API, secret, nom d'expéditeur, format de numéro, coût unitaire ;
  - **WhatsApp Business** : identifiant du numéro, identifiant du compte, jeton d'accès, nom et langue de chaque modèle de message approuvé ;
  - **E-mail** : serveur, port, chiffrement, identifiant, mot de passe, adresse et nom d'expéditeur.

  Chaque canal a un interrupteur Actif / Inactif et un bouton **Envoyer un message de test** (vers un numéro ou une adresse saisis). Le résultat s'affiche immédiatement (succès ou message d'erreur du fournisseur).
- **F-ADM-07 Facturation FNE** (RG-FNE-03) · Gérant pour son centre, `platform_admin` pour le CNCGA : mode de certification (API DGI / Sell Flow / manuel), identifiants chiffrés, bouton « Tester », nombre d'essais.
- **F-ADM-05 Habilitations : une interface dédiée par rôle** (RG-ROLE-00 à 07, D-05) :
  - **Liste des rôles** : une carte par rôle (libellé, description, nombre d'utilisateurs, nombre de droits accordés, mention « personnalisé pour votre centre » le cas échéant) → ouvre l'**interface du rôle**.
  - **Interface du rôle** : tous les droits du rôle regroupés par module (Adhérents, Portefeuilles, Paiements et factures, Documents, Obligations et clôture, Rapport, Préoccupations, Formations, Communications, Bancabilité, Paramètres…), une case à cocher par droit avec sa description ; droits verrouillés grisés avec un cadenas et l'explication ; boutons « Tout accorder / Tout retirer » par module ; liste des utilisateurs qui ont ce rôle ; « Revenir aux droits par défaut » pour ce rôle.
  - **Réseau** (administrateur de la plateforme) : interfaces de tous les rôles (CNCGA, CGA, adhérent), valeurs par défaut du réseau.
  - **CGA** (gérant) : interfaces des rôles de son centre (hors gérant) ; les cases différentes du paramétrage réseau sont signalées « personnalisé pour votre centre ».
  - Avant d'enregistrer, un aperçu indique le nombre d'utilisateurs concernés et les droits ajoutés ou retirés.
  - **Par utilisateur** (RG-ROLE-07) : depuis la fiche d'un utilisateur, le « nuage d'habilitations » affiche tous les droits de son profil sous forme de cases à cocher ; chaque case modifiée est marquée « ajusté pour cet utilisateur » et peut être remise au défaut du profil.

---

## 7. Conservation des données

| Données | Durée | Ensuite |
|---|---|---|
| Dossier d'un adhérent radié | 10 ans après la radiation | Anonymisation |
| Contrats, attestations, reçus, factures (avec leurs éléments de certification FNE) | 10 ans | Suppression |
| Dossiers du portefeuille Cabinet clos | 10 ans après la clôture | Anonymisation |
| Rapports trimestriels (toutes versions) | 10 ans | Archivage |
| Obligations et paiements suivis | 10 ans | Archivage |
| Préoccupations et échanges | 5 ans | Suppression |
| Historique des messages envoyés | 2 ans | Suppression |
| Journal d'activité (toutes les actions) · journal des modifications | 2 ans · 5 ans (paramétrables) | Suppression |
| Utilisateur inactif | Désactivé à 12 mois, supprimé à 24 mois après information | — |
| Données d'un CGA résilié | 6 mois après résiliation, sous réserve des durées ci-dessus | Suppression |

Les durées de 10 ans s'alignent sur l'obligation de conservation des documents comptables (OHADA / SYSCOHADA).

---

## 8. Exigences non fonctionnelles (vues du produit)

| Domaine | Exigence |
|---|---|
| Accessibilité mobile | Toutes les fonctions adhérent utilisables sur un téléphone de 360 px de large ; fonctions CGA et CNCGA utilisables sur tablette, optimisées pour ordinateur |
| Rapidité | Une page courante s'affiche en moins de 2 s sur une connexion 4G moyenne ; la recherche d'un adhérent répond en moins de 1 s |
| Disponibilité | 99,5 % aux heures ouvrées ; pas de maintenance les 10 et 15 de chaque mois ni les 5 derniers jours d'un trimestre |
| Volumes | 111 CGA, 20 abonnés la première année, jusqu'à 50 000 adhérents à terme |
| Sécurité | Section 3.4 ; cloisonnement vérifié par des tests automatiques |
| Traçabilité | RG-SEC-05 |

---

## 9. Points restant à confirmer (n'empêchent pas le développement)

Les valeurs par défaut ci-dessus s'appliquent et sont paramétrables.

| Point | Valeur appliquée | Qui confirme |
|---|---|---|
| Périmètre du module Bancabilité | Inclus, activable par CGA, développé en dernier | Bureau du CNCGA |
| Modalités de paiement de la cotisation | Versements libres avant le 30 juin | Bureau du CNCGA |
| Renouvellement d'agrément | Alertes à 6 et 3 mois | SP-CGA / DGI |
| Délai de radiation d'un adhérent RSI | 90 jours | SP-CGA / DGI |
| Modèle du courrier de notification de radiation | Modèle DC-KNOWING | SP-CGA / DGI |
| Acceptation du rapport modernisé seul (sans classeur de 14 feuilles) | Les deux sont produits (RG-RPT-10) | SP-CGA / DGI |
| Mesure exacte des critères du score bancaire | Hypothèses du tableau F-BNK-03, paramétrables | Banques partenaires (le CNCGA ajuste le paramètre) |
| Accès à l'API FNE de la DGI (identifiants, environnement de test) | Mode manuel par défaut (RG-FNE-03) | DGI / chaque CGA |
| Normalisation des reçus (RNE) | Reçus non normalisés, mécanisme prêt (RG-FNE-05) | DGI |
| API de Compta Flow (format, authentification) | Import Excel en attendant (RG-DATA-07) | DC-KNOWING (éditeur de Flow) |
| Liste des apporteurs d'affaires et libellés « Accessoire n » | Saisie par chaque centre | Gérant du CGA |
| Tarifs SMS / WhatsApp et quota | 200 SMS / mois / CGA | Équipe projet |
| Définition « cotisation TEE / RME augmentée » | Paramétrable (RG-RPT-08) ; défaut : montant annuel dû N > N-1 | SP-CGA (le CNCGA ajuste le paramètre) |
| Droits du juriste, du fiscaliste, du chargé des cotisations, du formateur et de l'accès adhérent secondaire | Paramétrables par profil et par utilisateur (RG-ROLE-02, 03, 07) ; défaut : section 3.1 | CNCGA ou gérant du CGA, à tout moment |

### Données provisoires, remplaçables par les données réelles

Le développement ne s'arrête pas en attendant les données réelles : chaque donnée de départ est chargée en **version provisoire**, puis remplacée **dans l'application, sans développement**.

| ID | Règle |
|---|---|
| RG-DATA-01 | Les 6 jeux de données attendus (liste des CGA ; DR, CDI et communes ; catalogue des obligations ; modèles de documents ; classeur officiel du rapport ; codes d'activité et références nationales) sont **modifiables à l'écran** (ajout, modification, désactivation) **et ré-importables** par fichier Excel (remplacement ou mise à jour), avec rapport d'anomalies. |
| RG-DATA-02 | Une donnée chargée provisoirement porte le statut « provisoire », affiché par un badge dans les écrans de paramétrage. Elle passe « validée » quand le CNCGA ou l'administrateur la confirme. Un indicateur « n éléments provisoires » figure dans les paramètres. |
| RG-DATA-03 | Les modèles de documents et le classeur officiel sont **versionnés** : une nouvelle version déposée ne change rien aux documents déjà émis (chacun garde la version qui l'a produit) ; elle s'applique aux productions suivantes une fois activée. |
| RG-DATA-04 | Le catalogue des obligations est **versionné** : l'import d'une nouvelle version propose de mettre à jour les obligations des adhérents (aperçu des ajouts, retraits et changements d'échéance avant confirmation) ; les échéances passées ne sont pas modifiées. |
| RG-DATA-05 | Une valeur de référentiel utilisée par des données existantes (ex. un CDI rattaché à des adhérents) ne peut pas être supprimée, seulement désactivée ou fusionnée avec une autre. |

### Documents attendus pour démarrer
Classeur officiel du rapport (version en vigueur) · référentiel des 42 obligations (DC-KNOWING) · liste des DR et CDI · nomenclature des codes d'activité · modèles anonymisés du contrat, de l'attestation et des reçus · liste des 111 CGA · barème d'honoraires d'exemple · logo et charte du CNCGA.
