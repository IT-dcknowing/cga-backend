# Consignes pour l'agent de développement — CGA Connect

> À placer à la racine du dépôt (sous le nom `AGENTS.md`, et en copie `CLAUDE.md`). L'agent lit ce fichier avant toute action.

## Documents de référence (dans `docs/`)
01-PRD (quoi) · 02-TRD (comment) · 03-APPFLOW (écrans et navigation) · 04-UX-UI-DESIGN-BRIEF (apparence) · 05-BACKEND-SCHEMA (données) · 06-IMPLEMENTATION-PLAN (ordre de construction).

En cas de doute, ces documents font foi. Ne jamais inventer une règle de gestion : si elle manque, s'arrêter et poser la question.

## ⛔ CONSIGNE FERME — tests de non-régression et de couverture

**Après chaque fonctionnalité (chaque étape numérotée du 06-IMPLEMENTATION-PLAN), les tests de non-régression et de couverture doivent être écrits ET exécutés avec succès. Ne jamais passer à une autre fonctionnalité tant que ces tests ne sont pas validés.**

1. **Écrire les tests de la fonctionnalité** :
   - unitaires, avec l'identifiant `RG-xxx` dans le nom du test ;
   - API : succès, 422, 403 / 404 pour chaque rôle, 409 ;
   - composants frontend.
2. **Lancer la suite COMPLÈTE**, pas seulement les nouveaux tests :
   - `composer test` ;
   - `npm run test:ci` ;
   - le test de cloisonnement.
3. **Vérifier la couverture** :
   - ≥ 85 % des lignes des fichiers touchés ;
   - backend global ≥ 80 % ;
   - Actions et Policies ≥ 90 % ;
   - frontend ≥ 70 % ;
   - aucune règle `RG-` sans test.
4. **Tout doit être vert.** Interdit :
   - désactiver, supprimer, `skip` ou affaiblir un test existant ;
   - baisser un seuil ;
   - « revenir plus tard » sur un test rouge.
5. **Reporter dans la demande de fusion** :
   - nombre de tests et résultat ;
   - couverture ;
   - règles `RG-` couvertes.
6. **Fin de lot** : rejouer les parcours Playwright disponibles avant la recette QA.

Si un test échoue, la seule action autorisée est de corriger la fonctionnalité en cours.

## Autres règles impératives
- Construire **dans l'ordre** du plan, une étape à la fois.
- **Cloisonnement** : toute donnée d'un CGA porte `organization_id` et passe par le scope `BelongsToOrganization`. Le CNCGA ne reçoit jamais de donnée nominative d'adhérent.
- **Portefeuilles** : seul le portefeuille **CGA** alimente le rapport trimestriel, les états réglementaires, les documents d'adhésion et les statistiques du CNCGA ; toute sortie de ce type passe par le scope `regulatory()`. Le portefeuille **Cabinet** et l'étiquette d'apporteur (« Accessoire n ») sont internes au centre : jamais dans un document officiel, un rapport ou une statistique ; l'apporteur n'est renvoyé qu'avec la permission `members.referrers.view`.
- **Contrat ↔ portefeuille CGA** : un dossier n'est dans le portefeuille CGA que s'il a un contrat CGA ; il n'en sort que par une radiation validée (dossier clos ou basculé dans le portefeuille Cabinet). Un apporteur (« Accessoire n ») ne peut être rattaché qu'à un dossier du portefeuille CGA.
- **Radiation toujours manuelle** : aucune tâche planifiée, aucun Job, aucun écouteur ne crée ni ne valide une radiation ; la plateforme alerte et propose seulement.
- **Aucune décision automatique de sanction** : pas de suspension, pas de blocage d'attestation, pas de coupure d'accès pour impayé. La plateforme alerte ; le gérant ou la Présidente décide (blocage manuel tracé).
- **Exclusivité d'adhésion bloquante** : jamais deux portefeuilles CGA pour un même NCC ; le contrôle est refait côté serveur dans la transaction, sans jamais révéler l'autre CGA.
- **FNE** : aucune facture n'est remise au client avant certification ; une facture certifiée est immuable (correction par avoir). Les certifieurs et Compta Flow passent par leurs interfaces (`FneCertifier`, `FlowConnector`) ; en CI, uniquement des bouchons, jamais d'appel réseau réel. Une donnée de source `compta_flow` fait foi et n'est pas modifiable à la main.
- **Rien en dur** :
  - les valeurs « à confirmer » sont des paramètres (`settings`) ;
  - les droits des rôles sont en base (`role_permissions`), à l'exception des permissions verrouillées ;
  - les identifiants SMS, WhatsApp et e-mail sont saisis dans l'écran « Envois », jamais dans le code ni dans `.env` ; de même pour les identifiants FNE et Compta Flow (écrans de paramétrage du centre) ;
  - les droits se résolvent dans l'ordre verrou → ajustement de l'utilisateur → surcharge du CGA → réseau ; le frontend affiche les menus selon les droits effectifs renvoyés par `/me`, jamais selon le seul rôle ;
  - les données de référence sont provisoires et modifiables.
- Toute écriture métier passe par une **Action** transactionnelle.
- ⛔ **Règle stricte — journalisation de toute action.** Toute action de l'application est journalisée, **lectures comprises** :
  - requêtes API, connexions, téléchargements, exports, erreurs et refus → `activity_logs` ;
  - Jobs, tâches planifiées, envois → `activity_logs` ;
  - modifications avec avant / après → `audit_logs`.

  Aucune route, aucun Job, aucune commande sans trace. Jamais de secret en clair dans un journal (02-TRD §6.5).
- ⛔ **Règle stricte — variabilisation.** Toute valeur susceptible de changer (délai, montant, seuil, durée, format de numéro, limite, texte, horaire) est une variable :
  - `.env` lu via `config/` ;
  - `settings` déclarés dans le registre ;
  - fichiers de traduction ;
  - tables de référentiels.

  Jamais de valeur en dur. `env()` est interdit hors de `config/` (02-TRD §7.6).
- ⛔ **Règle stricte — sécurité.**
  1. Respecter **toujours les 10 risques de l'OWASP Top 10** (édition 2025 ou en vigueur), dans chaque fonctionnalité : mesures détaillées dans 02-TRD §6.7.
  2. **Ne jamais faire confiance aux données qui viennent du frontend** :
     - tout est validé par une `FormRequest` ;
     - seules les données de `validated()` sont utilisées (jamais `$request->all()`, jamais `$guarded = []`) ;
     - `organization_id`, utilisateur, rôle, statuts, numéros, montants et scores sont déterminés par le serveur, jamais lus dans la requête ;
     - chaque requête est autorisée par une Policy, même si le bouton est caché ;
     - tout identifiant reçu est recherché dans le périmètre de l'utilisateur ;
     - les fichiers sont vérifiés par leur contenu ;
     - les tris et filtres sont limités à une liste blanche ;
     - les réponses passent par des `JsonResource` explicites.
  3. Interdits : `whereRaw` / `DB::raw` avec une donnée utilisateur, `unserialize`, `dangerouslySetInnerHTML`, secret dans `localStorage`, dépendance non déclarée. `composer audit` et `npm audit` doivent être verts.
- ⛔ **`try/catch` obligatoire** : toute action de contrôleur susceptible de générer une erreur passe par `BaseApiController::handle()` (02-TRD §4.4). Même règle dans les Jobs et commandes, qui relancent l'exception après journalisation. Interdit : un `catch` vide ou sans journalisation.
- **Infrastructure de départ** : pas de Docker, pas de S3.
  - Fichiers sur le disque du serveur via `StorageService` (disque `private`).
  - Files, cache et sessions sur `database`, basculables vers `redis` par configuration.
  - PDF via LibreOffice (`PdfConverter`).
  - Ne jamais coder en dépendant d'un de ces choix : toujours passer par l'abstraction Laravel.
- Textes de l'interface en français ; apparence conforme au 04-UX-UI.
