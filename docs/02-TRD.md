# 02 — TRD · Document d'exigences techniques · CGA Connect

> Version 1.3 · 15/10/2026 (précisions du Directeur : deux portefeuilles natifs, contrat ↔ portefeuille CGA, radiation toujours manuelle, interface d'habilitations par rôle, Accessoires dans le portefeuille CGA seulement) · v1.2 (intégration du compte rendu du 14/10/2026 : portefeuilles CGA / Cabinet / Accessoires, habilitations par utilisateur, radiation en deux temps, facturation FNE, connecteur Compta Flow, rapport modernisé, campagne de clôture) · v1.1 du 10/10/2026 : sans Docker ni S3 au démarrage, files basculables, try/catch, journalisation intégrale, variabilisation · Complète le 01-PRD (fonctionnalités `F-xxx`, règles `RG-xxx`).
> Ce document fixe les choix techniques. Un agent de développement **applique ces décisions sans les rediscuter**. Toute exception doit être ajoutée au registre de la section 14 avec sa justification.

---

## 1. Principes directeurs

1. **Simplicité d'abord** : l'équipe compte 1 développeur backend et 1 développeur frontend. On évite toute brique qui n'apporte pas un bénéfice direct (pas de microservices, pas de moteur de recherche externe, pas de GraphQL).
2. **Cloisonnement des données par organisation** : c'est l'exigence n°1 du Directeur (PRD §3.4). Il est garanti par le code, vérifié par des tests automatiques bloquants.
3. **La logique métier vit dans le backend.** Le frontend affiche et saisit ; il ne calcule ni statut, ni montant, ni droit. Les droits renvoyés par l'API pilotent l'affichage des boutons.
4. **Tout ce qui est long est asynchrone** : documents, rapports, imports, exports, envois de SMS / WhatsApp / e-mails.
5. **Tout est tracé** : **toute action** de l'application est journalisée, lectures comprises (§6.5, règle stricte).
6. **Rien en dur** : toute valeur susceptible de changer est variabilisée (§7.6, règle stricte).
7. **Démarrage sans conteneurs ni stockage cloud** : un serveur classique suffit. Docker, S3 et Redis restent possibles plus tard **par simple configuration**, sans modifier le code (§2, §12).

---

## 2. Stack imposée et versions

| Couche | Choix | Version minimale |
|---|---|---|
| Backend | Laravel (API uniquement) | 13.x |
| Langage backend | PHP | 8.3 |
| Base de données | PostgreSQL | 16 |
| Frontend | React + TypeScript, construit avec Vite | React 19, TypeScript 5, Vite 6+ |
| Files d'attente | **Basculables par configuration** : base de données PostgreSQL (`QUEUE_CONNECTION=database`, défaut au démarrage) ou Redis (`QUEUE_CONNECTION=redis`) | Redis 7 si utilisé |
| Cache, sessions, verrous | Même principe : `CACHE_STORE` et `SESSION_DRIVER` = `database` (défaut) ou `redis` | — |
| Stockage de fichiers | **Disque du serveur** (disque Laravel `private`, hors du dossier public) au démarrage ; bascule vers S3 possible plus tard par configuration (`FILESYSTEM_DISK`) | — |
| Conversion PDF | **LibreOffice installé sur le serveur** (mode `--headless`), derrière l'interface `PdfConverter` ; pilote Gotenberg possible plus tard | LibreOffice 7.6+ |
| Serveur web | Nginx (ou Apache) + PHP-FPM ; en local, Laragon ou équivalent | — |
| Conteneurs | **Aucun au démarrage** (décision du 10/10/2026). Docker pourra être ajouté plus tard sans changer le code | — |

---

## 3. Architecture

```
                ┌──────────────────────────────────────────────┐
 Navigateur ───▶│ Nginx  app.cgaconnect.ci                     │
 (React SPA)    │  /            → fichiers statiques du SPA    │
                │  /api/*       → PHP-FPM (Laravel)            │
                │  /verifier/*  → SPA (page publique)          │
                └──────────────┬───────────────────────────────┘
                               │
            ┌──────────────────┼────────────────────┬─────────────────────┐
            ▼                  ▼                    ▼                     ▼
      PostgreSQL 16     Files d'attente,      Disque du serveur      LibreOffice
      (données,         cache, sessions :     storage/app/private    headless
       journaux)        base de données       (documents, pièces,    (DOCX → PDF)
                        ou Redis (au choix)    exports)
                               ▲
                     Workers `queue:work` (Supervisor, ou Horizon si Redis)
                     Laravel Scheduler (cron chaque minute)
                               │
              Fournisseurs externes : SMTP · SMS · WhatsApp Cloud API
```

- **Monolithe modulaire** Laravel exposant une API REST JSON versionnée `/api/v1`.
- **SPA React** servie sur le **même domaine** que l'API (obligatoire pour l'authentification par cookie).
- Un seul dépôt Git, deux dossiers : `backend/` et `frontend/`, plus `infra/` (configurations Nginx, Supervisor, cron, scripts de déploiement et de sauvegarde) et `docs/` (ces documents).

---

## 4. Backend Laravel

### 4.1 Organisation du code (par domaine)

```
backend/app/
  Domain/
    Auth/            Organizations/   Users/         Referentials/
    Members/         Payments/        Obligations/   Documents/
    Reports/         Dues/            Concerns/      Trainings/
    Communications/  Notifications/   Statistics/    Imports/
    Banking/         Audit/           Platform/
    Portfolios/      Invoicing/ (FNE) Closing/       Integrations/ (ComptaFlow)
      ├─ Models/            Eloquent
      ├─ Actions/           1 classe = 1 opération métier (ex. ValidateMembership)
      ├─ Policies/
      ├─ Http/Controllers/  minces : valident, appellent une Action dans un try/catch (§4.4), renvoient une Resource
      ├─ Http/Requests/     FormRequest (validation)
      ├─ Http/Resources/    JsonResource
      ├─ Jobs/  Events/  Listeners/  Notifications/
      └─ Enums/             PHP enums (statuts, motifs, rôles…)
  Support/
    Tenancy/   (TenantContext, BelongsToOrganization, middleware)
    Numbering/ (SequenceService)
    Files/     (StorageService, PdfConverter)
    Logging/   (ActivityLogger, middleware LogActivity, JobActivityListener)
    Http/      (BaseApiController avec handle(), ApiErrorMapper)
    Config/    (SettingsService, registre des paramètres)
    Fiscal/    (FneCertifier : interface + pilotes, §10 bis)
```

**Règles :**
- Toute écriture métier passe par une **Action** exécutée dans `DB::transaction()`.
- Les contrôleurs ne contiennent pas de logique.
- Statuts, motifs et rôles sont des **enums PHP adossés à des chaînes**. En base, ce sont des `varchar` avec contrainte `CHECK`, pas des types `ENUM` PostgreSQL, pour faciliter les évolutions.

### 4.2 Paquets retenus

| Besoin | Paquet | Remarque |
|---|---|---|
| Authentification SPA par cookie | `laravel/sanctum` | Mode SPA stateful, pas de jetons |
| Supervision des files | `laravel/horizon` | **Seulement si** `QUEUE_CONNECTION=redis` ; sinon `queue:work` supervisé (Supervisor sous Linux, NSSM ou Planificateur de tâches sous Windows) |
| Filtres, tri et inclusions d'API | `spatie/laravel-query-builder` | `filter[...]`, `sort`, `include` |
| Documentation API | `dedoc/scramble` | OpenAPI généré automatiquement depuis le code |
| DOCX depuis modèle | `phpoffice/phpword` (TemplateProcessor) | Variables `${champ}`, lignes répétées `cloneRow` |
| Classeur Excel officiel | `phpoffice/phpspreadsheet` | Remplissage du modèle SP-CGA (voir §8.3) |
| Imports et exports de listes | `openspout/openspout` | Lecture et écriture en flux, faible mémoire |
| QR code | `endroid/qr-code` | PNG |
| Graphiques du rapport modernisé | `phpoffice/phpword` (graphiques natifs `addChart`) | Convertis en PDF par LibreOffice ; repli : images PNG produites côté serveur (§8.4) |
| Stockage S3 (plus tard) | `league/flysystem-aws-s3-v3` | Non installé au démarrage ; ajouté le jour de la bascule |
| Téléphones | `propaganistas/laravel-phone` | Validation et normalisation E.164 (+225) |
| Erreurs | `sentry/sentry-laravel` | |
| Tests | `pestphp/pest`, `pestphp/pest-plugin-laravel` | |
| Qualité | `laravel/pint`, `larastan/larastan` (niveau 6) | |

**Explicitement non retenus :**
- `spatie/laravel-permission` : les rôles sont fixes, une table de droits en configuration suffit (§6.2).
- Meilisearch / Scout : PostgreSQL `pg_trgm` + `unaccent` suffisent pour 50 000 adhérents.
- Packages d'audit tiers : un journal maison est adapté au cloisonnement (§6.5).

### 4.3 Conventions d'API

- Base `/api/v1`. JSON. Noms de champs en `snake_case`.
- Identifiants exposés : **ULID** (colonne `id` des tables métier). Jamais d'identifiant séquentiel dans une URL.
- Pagination : `?page=1&per_page=25` (100 maximum). Réponse `{ data: [], meta: { current_page, per_page, total, last_page } }`.
- Filtres, tri et inclusions : `?filter[status]=active&filter[search]=oscars&sort=-adhesion_date&include=sector`.
- Erreurs :
  - 422 : `{ message, errors: { champ: [..] } }` (format Laravel) ;
  - 403 : `{ message, code: "forbidden" }` ;
  - 409 : conflit métier, `{ message, code: "<code_metier>", details }` (ex. `ncc_already_active_elsewhere`, `membership_not_ready`) ;
  - 404 : identique pour « inexistant » et « hors de votre organisation » (ne pas révéler l'existence).
- Chaque ressource renvoie un bloc `can` avec les actions permises à l'utilisateur courant (ex. `"can": { "update": true, "radiate": false }`). Le frontend s'y fie pour afficher les boutons.
- Dates : `YYYY-MM-DD` ; horodatages en ISO 8601 UTC. Montants : entiers (FCFA).
- Opérations longues : `202 Accepted` + `{ job_id }`, avec suivi via `GET /api/v1/jobs/{id}` ou notification dans la plateforme.
- Chaque réponse porte l'en-tête `X-Request-Id` (identifiant de corrélation), repris dans les erreurs 500 et dans les journaux.

### 4.4 Gestion des erreurs dans les contrôleurs (règle stricte)

**Toute action de contrôleur susceptible de générer une erreur est exécutée dans un bloc `try/catch`.** Concrètement, toutes les actions qui appellent une Action, un service, la base, le disque, un fournisseur externe ou une file d'attente.

- Les contrôleurs héritent de `BaseApiController`, qui fournit `handle(callable $fn, string $action)` ; tout le corps de l'action est passé à `handle()` :
  ```php
  public function radiate(RadiateMemberRequest $request, Member $member): JsonResponse
  {
      return $this->handle(function () use ($request, $member) {
          $radiation = $this->radiateMember->execute($member, $request->validated());
          return new RadiationResource($radiation);
      }, action: 'members.radiate');
  }
  ```
- `handle()` contient le `try/catch` et convertit chaque exception en réponse JSON normalisée (§4.3) :

  | Exception attrapée | Réponse | Journalisation |
  |---|---|---|
  | `ValidationException` | 422 avec le détail des champs | `activity_logs` (résultat `validation_failed`) |
  | `AuthorizationException`, `AccessDeniedHttpException` | 403 `forbidden` | `activity_logs` (`denied`) + compteur d'alertes |
  | `ModelNotFoundException`, `NotFoundHttpException` | 404 | `activity_logs` (`not_found`) |
  | `BusinessRuleException` (exceptions métier levées par les Actions, avec un `code`) | 409 `{ message, code, details }` | `activity_logs` (`business_error`) |
  | `ThrottleRequestsException` | 429 | `activity_logs` (`throttled`) |
  | `ExternalServiceException` (SMS, WhatsApp, SMTP, LibreOffice) | 502 « Service externe indisponible, réessayez » | `activity_logs` + journal d'erreurs avec la réponse du fournisseur |
  | Toute autre `Throwable` | 500 « Une erreur est survenue (réf. {X-Request-Id}) » — jamais de détail technique au client | journal d'erreurs complet (trace, contexte, utilisateur, organisation) + Sentry + `activity_logs` (`error`) |

- Les transactions sont annulées automatiquement (`DB::transaction()` dans les Actions) ; `handle()` ne fait jamais de `commit` partiel.
- Les **Jobs** et **commandes planifiées** suivent la même règle : corps dans un `try/catch`, journalisation de l'échec, puis relance de l'exception pour que le mécanisme de nouvelle tentative s'applique (`$tries` et `$backoff` variabilisés).
- Il est interdit d'« avaler » une exception (`catch` vide ou sans journalisation).
- Le gestionnaire global d'exceptions de Laravel reste en place comme filet de sécurité, avec le même format de réponse.

---

## 5. Frontend React

### 5.1 Bibliothèques

| Besoin | Choix |
|---|---|
| Routage | `react-router` v7 (mode bibliothèque, routes déclarées en objets) |
| Données serveur | `@tanstack/react-query` v5 |
| Client HTTP | `axios` (`withCredentials`, en-tête `X-XSRF-TOKEN` automatique) |
| Formulaires | `react-hook-form` + `zod` (schémas partagés par fonctionnalité) |
| UI | Tailwind CSS v4 + `shadcn/ui` (Radix) — composants copiés dans `src/components/ui` |
| Tableaux | `@tanstack/react-table` (pagination, tri et filtres côté serveur) |
| Graphiques | `recharts` |
| Carte économique | `d3-geo` + GeoJSON des régions ivoiriennes simplifié (embarqué), regroupé par Direction régionale |
| Dates | `date-fns` (locale `fr`) |
| Notifications éphémères | `sonner` |
| Icônes | `lucide-react` |
| Tests | `vitest` + `@testing-library/react` ; E2E `@playwright/test` |
| Qualité | ESLint + Prettier, `tsc --noEmit` en CI |

Pas de gestionnaire d'état global (Redux, Zustand) : l'état serveur est dans React Query, l'état de session dans un contexte `AuthContext`.

### 5.2 Organisation

```
frontend/src/
  app/          router.tsx, providers.tsx, layouts/ (CncgaLayout, CgaLayout, MemberLayout, PublicLayout)
  components/   ui/ (shadcn), shared/ (DataTable, StatusBadge, PeriodSelector, FileUpload, EmptyState, ConfirmDialog…)
  features/
    auth/ dashboard/ organizations/ members/ payments/ obligations/ documents/
    reports/ dues/ concerns/ trainings/ communications/ statistics/ banking/ admin/
      ├─ api.ts       hooks React Query (useMembers, useValidateMembership…)
      ├─ schemas.ts   zod
      ├─ pages/       écrans
      └─ components/
  lib/          http.ts, permissions.ts, format.ts (FCFA, dates), i18n/fr.ts
  types/        types générés depuis l'OpenAPI (openapi-typescript)
```

- Les types TypeScript de l'API sont **générés** depuis le schéma OpenAPI produit par Scramble (`npm run gen:api`).
- Tous les libellés sont en français, centralisés dans `lib/i18n/fr.ts`.
- Responsive : mise en page mobile d'abord pour l'espace adhérent (≥ 360 px), barre de navigation basse sur mobile.

---

## 6. Sécurité

### 6.1 Authentification
- Sanctum en mode SPA : `GET /sanctum/csrf-cookie`, puis `POST /api/v1/auth/login` ; session HTTP stockée selon `SESSION_DRIVER` (`database` par défaut, `redis` possible).
- Cookies `Secure`, `HttpOnly`, `SameSite=Lax`.
- Mots de passe hachés en **Argon2id** ; 8 caractères minimum, refus des 10 000 mots de passe les plus courants.
- Limitation des essais :
  - connexion : 5 échecs / 15 min par identifiant + IP ;
  - demande de code SMS : 3 / heure par numéro ;
  - vérification du code : 3 essais.
- Invitations et réinitialisations : jeton aléatoire de 32 octets ; **seule son empreinte SHA-256 est stockée** ; usage unique ; expiration 7 jours (invitation) ou 10 minutes (code SMS).
- Déconnexion après **30 min** d'inactivité pour les agents, **60 min** pour les adhérents (durée de session glissante selon l'espace actif ; valeurs variabilisées `session.timeout_agent_minutes`, `session.timeout_member_minutes`).

### 6.2 Espace actif et droits
- Après connexion, `GET /api/v1/me` renvoie l'utilisateur et la liste de ses **rattachements** (`memberships` : organisation, rôle, adhérent éventuel).
- `POST /api/v1/me/space { membership_id }` enregistre le rattachement actif **dans la session serveur**. Tant qu'aucun espace n'est choisi, les routes métier renvoient 409 `space_required`.
- Le middleware `ResolveTenant` charge le rattachement actif et alimente le singleton `TenantContext` : `organization_id`, `organization_type` (cncga | cga | platform), `role`, `member_id` (si adhérent).
- **Droits paramétrables (RG-ROLE-01 à 06)** :
  - le **catalogue des permissions** est défini dans le code (`config/permissions.php` : code, libellé, module, espace, `locked`) ;
  - leur **attribution aux rôles** est stockée en base (`role_permissions`, 05-BACKEND-SCHEMA §2) à deux niveaux : réseau (`organization_id` nul) et surcharge par CGA ;
  - la matrice par défaut du PRD §3.3 est chargée par le seeder `PermissionSeeder` et sert pour « Revenir aux valeurs par défaut » ;
  - **API par rôle** (interface dédiée à chaque rôle, F-ADM-05) : `GET /api/v1/{network|cga}/roles` (rôles gérables avec compteurs) ; `GET /api/v1/{network|cga}/roles/{role}/permissions` (droits du rôle groupés par module, valeur, `locked`, `customized`) ; `PUT …/roles/{role}/permissions` (liste complète des droits accordés ; le serveur ignore toute permission verrouillée ou hors catalogue et refuse `cga_manager` côté CGA) ; `POST …/roles/{role}/permissions/reset` ; `GET …/roles/{role}/impact` (aperçu : utilisateurs concernés, droits ajoutés / retirés).
- **Habilitations par utilisateur (RG-ROLE-07)** : table `user_permission_overrides` (rattachement, permission, `granted` vrai / faux), gérée par le gérant pour son centre et par `platform_admin` pour le CNCGA. Un utilisateur ne peut jamais modifier ses propres habilitations.
- **Résolution des droits** d'un rattachement :
  1. permissions verrouillées : valeur du code, toujours ;
  2. sinon, ajustement individuel du rattachement s'il existe ;
  3. sinon, surcharge du CGA pour le rôle si elle existe ;
  4. sinon, valeur réseau.

  Pour un utilisateur qui cumule plusieurs rôles dans la même organisation, une permission est accordée si l'un des rôles l'accorde, sauf retrait individuel explicite. Le résultat est mis en cache (`CACHE_STORE` : base de données ou Redis) par rattachement, invalidé à chaque modification de rôle, de surcharge ou d'ajustement. L'API `GET /api/v1/cga/users/{id}/permissions` renvoie pour chaque droit sa valeur et son **origine** (`locked`, `user`, `organization`, `network`).
- Les Policies combinent **permission résolue et périmètre** (même organisation, même adhérent). Les invariants de cloisonnement (RG-SEC-01, 02) sont dans le code des scopes et Policies, **pas** dans les permissions : aucun paramétrage ne peut les lever.
- Les gates sont exposées au frontend via le bloc `can` (§4.3) et la liste `permissions` de `/me`.

### 6.3 Cloisonnement (RG-SEC-01 à 04)
- Toute table de données d'un CGA porte `organization_id`, non nul, indexé.
- Le trait `BelongsToOrganization` ajoute un **scope global** filtrant sur `TenantContext::organizationId()` et renseigne la colonne à la création. Il lève une exception si aucun contexte n'est défini, sauf dans un bloc explicite `Tenancy::bypass(fn () => …, reason: '...')` qui est **journalisé**.
- Contournements autorisés (et uniquement ceux-là) :
  - calcul des statistiques agrégées (§9) ;
  - détection d'un NCC actif dans un autre CGA (renvoie un booléen, jamais l'identité du CGA) ;
  - commandes planifiées système ;
  - accès `platform_admin`, toujours journalisé dans `platform_access_logs` (RG-SEC-04).
- Le CNCGA n'a **aucune route** qui renvoie des données nominatives d'adhérents.
- **Portefeuilles (RG-PTF-01 à 07, RG-SEC-08)** :
  - `members.portfolio` (`cga` | `cabinet`) est déterminé par le serveur (création, bascule) ; jamais lu tel quel dans une requête de modification ;
  - les requêtes du rapport trimestriel, des états réglementaires, des agrégats `stat_*` et des exports « administration » passent par le scope `Member::scopeRegulatory()` (= portefeuille CGA) ; un test vérifie qu'un dossier Cabinet n'apparaît dans **aucune** de ces sorties ;
  - les deux portefeuilles existent dans chaque centre (aucun paramètre d'activation) ; les routes du portefeuille Cabinet exigent la permission `members.cabinet.view` ;
  - **contrat ↔ portefeuille CGA (RG-PTF-08)** : contrainte `CHECK (portfolio <> 'cga' OR status IN ('draft') OR cga_contract_signed_on IS NOT NULL)` ; les seules Actions qui écrivent `portfolio = 'cga'` sont `ValidateMembership` et `MoveToCgaPortfolio`, qui exigent la date de signature et la confirmation ; la seule qui fait sortir du portefeuille CGA est `ValidateRadiation` ;
  - **bascule CGA → Cabinet** : pas d'Action propre ; l'action d'écran « Basculer vers le portefeuille Cabinet » appelle `RequestRadiation` puis `ValidateRadiation` avec `outcome = moved_to_cabinet` (deux confirmations de l'utilisateur) ;
  - l'apporteur d'affaires (`referrer_id`, libellé « Accessoire n ») n'est autorisé que pour `portfolio = 'cga'` (contrainte `CHECK (referrer_id IS NULL OR portfolio = 'cga')`) ; la bascule vers le Cabinet met `referrer_id` à nul et conserve l'ancien apporteur dans `portfolio_movements.previous_referrer_id` ; il n'est inclus dans une `JsonResource` que si l'utilisateur a la permission `members.referrers.view` et n'est jamais injecté dans un modèle de document officiel.
- **Radiation toujours manuelle (RG-ADH-18)** : seules `RequestRadiation` et `ValidateRadiation`, appelées depuis une route authentifiée par un utilisateur habilité, créent ou valident une radiation ; aucune tâche planifiée, aucun Job, aucun écouteur ne peut les appeler (test d'architecture Pest : ces Actions ne sont référencées que par leurs contrôleurs ; `members:radiation-expiry` ne fait que restreindre l'accès en ligne des dossiers déjà radiés).
- **Exclusivité d'adhésion (RG-ADH-05)** : l'Action `AssertMembershipExclusivity` (contournement journalisé) cherche le NCC normalisé dans le portefeuille CGA des **autres** organisations aux statuts `active`, `suspended` ou `radiation_pending` ; elle renvoie un booléen et lève `BusinessRuleException('membership_exclusive')` (409) sans jamais révéler l'organisation. Elle est appelée à la validation d'adhésion **et** à la bascule Cabinet → CGA, dans la transaction, avec verrou consultatif PostgreSQL sur le NCC (`pg_advisory_xact_lock`) pour empêcher deux validations simultanées dans deux CGA.
- **Test bloquant en CI** : un test paramétré parcourt toutes les routes `/api/v1` avec un utilisateur du CGA A et des identifiants d'objets du CGA B, et exige 404 ou 403 partout.

### 6.4 Données et fichiers
- Fichiers stockés sur le **disque du serveur**, disque Laravel `private` (`storage/app/private/…`), **jamais** dans un dossier accessible directement par le web.
- Tout accès passe par `StorageService` (API Flysystem de Laravel), si bien que la bascule vers S3 se fait en changeant `FILESYSTEM_DISK` sans toucher au code.
- Téléchargement :
  1. l'API contrôle les droits et délivre un **lien temporaire signé** (route Laravel `temporarySignedRoute`, validité variabilisée `files.download_link_minutes`, défaut 5 min) ;
  2. un contrôleur de téléchargement vérifie la signature, journalise l'accès et diffuse le fichier.
- Arborescence : `orgs/{organization_id}/{type}/{yyyy}/{ulid}.{ext}`. Le chemin racine est variabilisé (`FILES_ROOT`).
- Espace disque surveillé : alerte quand le disque dépasse le seuil variabilisé `files.disk_alert_percent` (défaut 80 %).
- Chiffrement applicatif (cast `encrypted` de Laravel) : numéros de pièce d'identité, motifs et observations de radiation pour manquements graves.
- Pièces jointes : PDF, JPG, PNG (Word et Excel pour les dépôts CGA) ; 10 Mo maximum ; contrôle du type réel (signature binaire) ; nom de fichier régénéré.
- En-têtes HTTP : HSTS, CSP stricte (`default-src 'self'`), `X-Content-Type-Options`, `Referrer-Policy: strict-origin-when-cross-origin`.

### 6.5 Journalisation intégrale (règle stricte : toute action est journalisée)

**Toute action dans l'application est journalisée, sans exception.** Deux journaux complémentaires :

| Journal | Ce qu'il enregistre | Comment |
|---|---|---|
| **`activity_logs`** — journal d'activité | **Chaque requête API**, lecture comprise : consultation d'une liste ou d'une fiche, recherche, téléchargement, export, connexion, échec de connexion, déconnexion, changement d'espace, accès refusé, erreur. Également chaque exécution de **Job**, de **commande planifiée**, chaque **envoi** (SMS, WhatsApp, e-mail) et chaque consultation de la **page publique de vérification** | Middleware `LogActivity` sur toutes les routes, écouteurs sur les événements de files et du Scheduler, appel explicite dans les canaux d'envoi |
| **`audit_logs`** — journal des modifications | Chaque **création, modification, suppression** de donnée avec les **anciennes et nouvelles valeurs** de chaque champ modifié, et les événements métier (radiation, émission ou annulation de document, transmission de rapport, confirmation de paiement, changement de droits ou de paramètres, contournement du cloisonnement) | Trait `Auditable` sur tous les modèles + écriture explicite dans les Actions |

Contenu d'une ligne `activity_logs` :
- quand, qui (utilisateur, rattachement, rôle, organisation), ou acteur système (`system:scheduler`, `system:queue`) ;
- quoi : nom de l'action (ex. `members.show`, `members.radiate`), méthode, route, identifiant de l'objet visé ;
- paramètres de la requête **expurgés** : mots de passe, codes, jetons et secrets remplacés par `***` (liste des champs sensibles variabilisée) ;
- résultat (`success`, `validation_failed`, `denied`, `not_found`, `business_error`, `error`, `throttled`), code HTTP, durée en ms ;
- IP, user-agent, `X-Request-Id`.

Règles :
- L'écriture du journal **ne doit jamais faire échouer** l'action de l'utilisateur. Le middleware enregistre après la réponse (`terminate`) ; en cas d'échec d'écriture, le message est écrit dans le fichier journal de secours `storage/logs/activity-fallback.log`.
- Les lignes de `activity_logs` et `audit_logs` ne sont jamais modifiées ni supprimées par l'application (seule la purge de rétention les supprime).
- Durée de conservation variabilisée : `logs.activity_retention_months` (défaut 24), `logs.audit_retention_months` (défaut 60, PRD §7). Tables partitionnées par mois.
- Consultation : écran Journaux de `/plateforme` (tout le réseau) et, pour le gérant, onglet « Journal » des paramètres du CGA (son centre uniquement). Le journal d'un dossier adhérent est l'onglet « Historique » (03-APPFLOW).
- Côté frontend, seules les actions qui appellent l'API sont journalisées (une ouverture de modale sans appel n'est pas une action).

### 6.6 Page publique de vérification
- `GET /api/v1/public/verify/{token}` (sans authentification), limitée à 30 requêtes / min par IP (variabilisé).
- Renvoie uniquement les 5 informations de F-DOC-04. Chaque consultation est enregistrée (IP tronquée au /24).

### 6.7 Règle stricte de sécurité — OWASP Top 10 et « ne jamais faire confiance au frontend »

**Règle 1 — Les 10 risques de l'OWASP Top 10 (édition 2025, ou l'édition en vigueur au moment du développement) sont respectés dans chaque fonctionnalité, sans exception.**

| Risque OWASP 2025 | Mesures obligatoires dans CGA Connect |
|---|---|
| **A01 Contrôle d'accès défaillant** | Policy sur **chaque** route (aucune route métier sans `authorize`) ; scope `BelongsToOrganization` ; 404 pour un objet hors périmètre ; identifiants ULID non devinables ; liens de téléchargement signés et temporaires ; test de cloisonnement bloquant ; test « chaque rôle × chaque route » |
| **A02 Mauvaise configuration de sécurité** | `APP_DEBUG=false` hors local ; aucune page d'erreur détaillée ; en-têtes HSTS, CSP stricte, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy` ; CORS limité au domaine de l'application ; cookies `Secure`, `HttpOnly`, `SameSite` ; outils de développement (Telescope, Scramble `/docs/api`) désactivés ou protégés hors local ; dossier de stockage hors de la racine web ; droits de fichiers minimaux sur le serveur |
| **A03 Chaîne d'approvisionnement logicielle** | Fichiers `composer.lock` et `package-lock.json` versionnés ; `composer audit` et `npm audit --audit-level=high` **bloquants** en CI ; dépendances limitées à la liste du §4.2 et §5.1 (tout ajout = décision au registre §14) ; mises à jour de sécurité mensuelles ; aucun script ou CDN externe chargé par le frontend |
| **A04 Défaillances cryptographiques** | HTTPS obligatoire (TLS 1.2+) ; Argon2id pour les mots de passe ; jetons et codes seulement stockés sous forme d'empreinte SHA-256 ; champs sensibles et identifiants de fournisseurs chiffrés (`encrypted`) ; `APP_KEY` jamais versionnée ; sauvegardes chiffrées ; générateur aléatoire cryptographique (`random_bytes`) pour tout jeton |
| **A05 Injection** | Requêtes uniquement via Eloquent ou le Query Builder avec paramètres liés ; `DB::raw` / `whereRaw` interdits avec une donnée utilisateur ; champs de tri et de filtre limités à une **liste blanche** (`allowedSorts`, `allowedFilters`) ; aucune commande système construite avec une donnée utilisateur (appel de LibreOffice avec des chemins générés par le serveur et `Process` avec un tableau d'arguments) ; échappement automatique de React (`dangerouslySetInnerHTML` interdit) ; contenus des modèles de documents et des messages échappés ; protection contre l'injection de formules dans les exports Excel (préfixe `'` devant `= + - @`) |
| **A06 Conception non sécurisée** | Règles de confidentialité (PRD §3.4) appliquées dans le code, pas dans les droits paramétrables ; permissions verrouillées ; consentements tracés ; limites métier côté serveur (quotas SMS, envoi groupé > 50 validé, annulation de radiation ≤ 30 j) ; revue de sécurité de chaque lot avant la recette |
| **A07 Échecs d'authentification** | Limitation des essais (connexion, codes SMS, vérification) ; blocage temporaire ; messages qui ne révèlent pas l'existence d'un compte ; régénération de la session à la connexion et au changement d'espace ; expiration d'inactivité ; invitations à usage unique et expirantes ; mot de passe ≥ 8 caractères et refus des mots de passe courants ; jetons CSRF Sanctum |
| **A08 Défaillances d'intégrité des logiciels ou des données** | Empreinte SHA-256 de chaque document émis et vérification publique ; liens signés ; aucune désérialisation de données venant du client (`unserialize` interdit) ; fichiers déposés contrôlés par leur contenu réel, renommés, jamais exécutables ; déploiement uniquement depuis le dépôt Git par le script signé `deploy.sh` |
| **A09 Défaillances de journalisation et d'alerte** | Journalisation intégrale (§6.5) ; alertes : pics d'échecs de connexion, de refus 403, d'erreurs 500, contournements du cloisonnement, accès support ; journaux non modifiables par l'application ; aucune donnée sensible dans les journaux |
| **A10 Mauvaise gestion des conditions exceptionnelles** | `try/catch` obligatoire (§4.4) ; aucune information technique renvoyée au client ; transactions annulées en cas d'erreur (pas d'état partiel) ; comportement « fermé par défaut » : en cas d'erreur pendant un contrôle de droit, l'accès est **refusé** ; délais maximaux sur tous les appels externes (SMS, WhatsApp, SMTP, LibreOffice) |

**Règle 2 — Ne jamais faire confiance aux données qui viennent du frontend.** Le frontend est considéré comme **non fiable** : tout ce qu'il envoie peut avoir été modifié. Ses contrôles servent uniquement le confort de l'utilisateur ; **seuls les contrôles du backend comptent**.

1. **Toute donnée entrante est validée côté serveur** par une `FormRequest` : type, format, longueur maximale, liste de valeurs autorisées, existence et appartenance des identifiants à l'organisation courante (`Rule::exists(...)->where('organization_id', …)`). Même si le frontend a déjà validé.
2. **Seules les données validées sont utilisées** : `$request->validated()` ou `$request->safe()->only([...])`. Interdits : `$request->all()`, `$request->input()` non validé, `Model::create($request->all())`, `$guarded = []`. Chaque modèle déclare `$fillable` explicitement.
3. **Le serveur détermine lui-même** tout ce qui touche à l'identité, aux droits et à la valeur. Jamais lus depuis la requête :
   - `organization_id`, `user_id`, `member_id` de l'acteur, rôle, espace actif → depuis la session et le `TenantContext` ;
   - statuts, numéros (adhésion, reçus), montants calculés (restes, prorata, totaux, scores), dates de validation ou d'émission → calculés par les Actions ;
   - prix d'une formation, montant d'une cotisation, quotas → lus en base ou dans les paramètres.
4. **Chaque requête est autorisée côté serveur**, même si le bouton correspondant est masqué à l'écran. Le bloc `can` envoyé au frontend ne remplace jamais une Policy.
5. **Identifiants d'objets** reçus dans l'URL ou le corps : toujours recherchés **dans le périmètre** de l'utilisateur (scope), jamais par identifiant seul.
6. **Fichiers** : type vérifié par le contenu réel (pas par l'extension ni le type annoncé), taille maximale contrôlée côté serveur, nom remplacé, stockage hors racine web.
7. **Paramètres de liste** (tri, filtres, inclusions, pagination) : liste blanche, `per_page` plafonné.
8. **Données renvoyées au frontend** : uniquement via des `JsonResource` qui listent explicitement les champs ; aucun modèle sérialisé directement (risque d'exposer des champs cachés).
9. Le frontend ne stocke **aucun** secret ni jeton dans `localStorage` / `sessionStorage` ; l'authentification reste dans les cookies `HttpOnly`.

**Contrôles automatiques** :
- règles d'analyse statique interdisant `$request->all()`, `whereRaw` avec variable, `unserialize`, `env()` hors `config/`, `dangerouslySetInnerHTML` ;
- tests de sécurité (§13) ;
- `composer audit` et `npm audit` bloquants ;
- scan dynamique OWASP ZAP (mode « baseline ») sur la recette à chaque fin de lot.

---

## 7. Règles techniques transverses

### 7.1 Numérotations (RG-ADH-03, RG-PAY-02…)
- Table `sequences (organization_id, key, year, last_value)` ; clé par exemple `membership`, `receipt`, `invoice`, `attestation`, `notification_letter`, `dues_receipt`.
- `SequenceService::next($org, $key, $year)` : `SELECT … FOR UPDATE` puis incrément, dans la transaction de l'Action.
- Le numéro d'adhésion n'utilise pas d'année dans la clé (séquence continue) ; seule l'année de la date d'adhésion apparaît dans le numéro.
- **Formats variabilisés** (`settings`, RG-ADH-03) : `numbering.membership.pattern` (défaut `{YYYY}-{ABR}-{N:4}`, portefeuille CGA) et `numbering.client.pattern` (défaut `CAB-{ABR}-{N:5}`, portefeuille Cabinet, clé `client`). Une bascule Cabinet → CGA attribue un numéro d'adhésion et **conserve** le numéro client.
- **Double numérotation (RG-IMP-01)** : à l'import, l'ancien numéro va dans `members.legacy_number` (non unique, conservé tel quel, recherchable) ; un numéro CGA Connect est attribué par la séquence en triant les lignes importées par date d'adhésion puis par ancien numéro.
- Garanties : pas de doublon, pas de trou ; une Action annulée rend le numéro par simple rollback.

### 7.2 Montants, dates, fuseau
- Montants en `bigint` (FCFA entiers).
- Fuseau applicatif `Africa/Abidjan` (UTC+0) ; stockage `timestamptz`.

### 7.3 Jours ouvrés
- Table `holidays` gérée par le CNCGA. `BusinessCalendar::nextBusinessDay($date)` applique RG-OBL-06.

### 7.4 Recherche
- Extensions PostgreSQL `pg_trgm` et `unaccent`.
- Colonne générée `search_text` (raison sociale, sigle, NCC, numéro, téléphone) avec index GIN trigram ; recherche `ILIKE unaccent(...)`.

### 7.5 Paramètres
- Table `settings (organization_id nullable, key, value jsonb)`. Lecture : valeur du CGA, sinon valeur réseau, sinon valeur par défaut codée dans `config/cga.php`.
- Chaque clé est déclarée dans `config/cga.php` avec :
  - son type et son schéma de validation (ex. `banking.weights` : 4 entiers dont la somme fait 100) ;
  - son niveau autorisé (`network`, `cga` ou les deux) ;
  - le droit requis pour la modifier.
- Toute modification est journalisée (`audit_logs`, valeurs avant / après) et incrémente `settings_version`. Les calculs dont le résultat est conservé (rapport, score bancaire) enregistrent la valeur des paramètres utilisés.
- Paramètres « métier » exposés à l'écran : voir 05-BACKEND-SCHEMA §16 (`settings`).

### 7.6 Variabilisation (règle stricte : rien en dur)

**Toute valeur susceptible de changer est une variable, jamais une constante écrite dans le code.** Chaque type de valeur a sa place :

| Type de valeur | Emplacement | Exemples |
|---|---|---|
| Environnement et infrastructure | `.env` → lu **uniquement** via `config/*.php` (jamais `env()` hors des fichiers de configuration) | URL de l'application, base de données, `QUEUE_CONNECTION`, `CACHE_STORE`, `SESSION_DRIVER`, `FILESYSTEM_DISK`, `FILES_ROOT`, chemin du binaire LibreOffice (`LIBREOFFICE_BIN`), délai de conversion PDF, nom de domaine de vérification |
| Règles métier modifiables à l'écran | Table `settings` + registre `config/cga.php` (§7.5) | Délais (impayé 30 j, pièces 30 j, RSI 90 j, radiation annulable 30 j, accès radié 6 mois), montants par défaut (droit d'adhésion, cotisation), seuils (envoi groupé 50, quota SMS 200, moyenne ≥ 5 CGA), horizon des échéances, dates limites, prorata, règles du rapport, score bancaire |
| Paramètres techniques ajustables par l'administrateur | Table `settings` (niveau plateforme) | Durée des liens de téléchargement, taille maximale des fichiers, types de fichiers acceptés, limitations de débit (connexion, codes SMS, page de vérification), plage horaire d'envoi SMS / WhatsApp, durées de session, rétention des journaux, pagination par défaut et maximale |
| Formats de numérotation | Table `settings` | `numbering.membership.pattern` = `{YYYY}-{ABR}-{NNNN}`, `numbering.receipt.pattern` = `REC-{ABR}-{YYYY}-{NNNNN}`, etc. |
| Horaires des tâches planifiées, tentatives et délais des Jobs | `config/schedule.php`, `config/queue-jobs.php` (alimentés par `.env` si besoin) | `obligations:generate` à 01:00, `$tries = 3`, `$backoff = [60, 300, 900]` |
| Listes de valeurs (statuts, motifs, rôles) | Enums PHP (code) **et** libellés dans les fichiers de traduction | Les libellés affichés ne sont jamais écrits dans le code métier |
| Textes de l'interface et des messages | `lang/fr/*.php` (backend), `src/lib/i18n/fr.ts` (frontend) | Messages d'erreur, contenus des SMS / e-mails (modèles), libellés |
| Identifiants de fournisseurs | Table `messaging_providers` (chiffrée) | SMS, WhatsApp, SMTP (RG-NOT-05) |
| Données de référence | Tables de référentiels (RG-DATA) | DR, CDI, secteurs, obligations, modèles de documents |

Contrôles :
- Larastan et une règle de revue de code interdisent `env()` hors de `config/`.
- Toute valeur numérique « magique » (délai, seuil, montant) repérée en revue est refusée, sauf 0, 1 et les constantes mathématiques.
- Chaque nouvelle clé de `settings` est déclarée dans le registre avec sa valeur par défaut, son type, son niveau (plateforme, réseau, CGA) et le droit requis pour la modifier. Un test vérifie que toute clé lue dans le code est déclarée.

---

## 8. Documents

### 8.1 Modèles
- Modèles DOCX stockés en base (`document_templates`, versionnés) et sur le disque du serveur (via `StorageService`). Variables `${nom_variable}` ; tableaux répétés via `cloneRow`.
- Les modèles de la maquette (`contrat`, `attestation`, `rapportWord`, `rapport` .xlsx) sont la base. Ils doivent être **nettoyés de toute donnée réelle** puis balisés avec des variables.
- Identité du CGA injectée dans chaque modèle : dénomination, NCC, RCCM, agrément, siège, contacts, logo (image), signature (image), cachet (image), nom et qualité du signataire.

### 8.2 Chaîne de production (Job `GenerateDocument`, file `documents`)
1. Charger le modèle actif du type de document.
2. Remplir les variables (PhpWord) et produire le DOCX.
3. Générer le **jeton de vérification** (32 octets aléatoires, base64url) et le QR PNG (URL `https://<domaine>/verifier/{jeton}`), insérés à l'emplacement `${qr_code}`.
4. Convertir en PDF via l'interface `PdfConverter`. Pilote par défaut `libreoffice` : appel de `soffice --headless --convert-to pdf` sur un fichier temporaire, avec un **profil utilisateur LibreOffice dédié par worker** (évite les blocages d'instances simultanées) et un délai maximal variabilisé (`pdf.timeout_seconds`, défaut 60). Le pilote `gotenberg` reste possible plus tard (`PDF_CONVERTER=gotenberg`).
5. Calculer l'empreinte SHA-256 du PDF ; stocker DOCX et PDF via `StorageService` sous `orgs/{org_id}/documents/{yyyy}/{ulid}.{ext}`.
6. Enregistrer `generated_documents` ; notifier l'utilisateur (événement `DocumentReady`) et, si besoin, l'adhérent.

Délai cible : moins de 10 s par document. Nouvelles tentatives : 3, avec intervalle croissant (variabilisés). Les conversions sont traitées par une file dédiée `documents` à **un seul worker** au démarrage (`documents.workers`, variabilisé), pour ne pas surcharger le serveur.

### 8.3 Classeur officiel du rapport trimestriel
- Le modèle SP-CGA (14 feuilles) contient des formes dessinées que certaines bibliothèques gèrent mal (`openpyxl` échoue à le lire).
- **Décision** : remplissage avec PhpSpreadsheet sur un **modèle nettoyé et validé**.
- **Validation obligatoire au Lot 0** : ouvrir, remplir et réenregistrer le modèle, puis comparer visuellement le résultat (page de garde, logos, formes, mises en forme) dans Excel et LibreOffice.
- **Plan B si la fidélité n'est pas parfaite** : manipulation directe du XML du fichier `.xlsx` (copie du modèle + écriture des cellules dans `sheetN.xml` et `sharedStrings.xml`) via une classe dédiée `OfficialWorkbookWriter`.
- Les blocs « par CDI » des feuilles sont produits en dupliquant le bloc modèle (titre CDI, en-têtes, lignes, ligne TOTAL) autant de fois qu'il y a de CDI.
- Depuis le compte rendu du 14/10/2026, ce classeur est un **export de compatibilité** (RG-RPT-10), produit seulement si le paramètre réseau `report.legacy_workbook_enabled` est vrai (défaut : vrai). La validation au Lot 0 reste nécessaire tant qu'il est activé.

### 8.4 Rapport trimestriel modernisé (RG-RPT-09 à 12)
- Un seul service de calcul `QuarterlyReportCalculator` produit un **objet de données** (agrégats réglementaires par CDI, séries des graphiques, synthèse chiffrée pré-rédigée) à partir du scope `regulatory()`. Le rapport modernisé et l'export de compatibilité lisent **le même objet** : les agrégats sont identiques par construction (test unitaire de comparaison).
- Rendu : modèle DOCX `rapport_modernise` (variables, tableaux `cloneRow`, emplacements de graphiques) rempli par PhpWord ; graphiques natifs (`addChart` : secteurs, barres, courbes) ; conversion PDF par la chaîne §8.2.
- **Validation au Lot 0** : rendu des graphiques PhpWord par LibreOffice. **Repli** si le rendu n'est pas fidèle : graphiques produits en PNG côté serveur (bibliothèque de dessin PHP ou rendu SVG → PNG) et insérés comme images ; décision consignée en T-21.
- La synthèse pré-rédigée est construite à partir de phrases modèles dans les fichiers de traduction (`lang/fr/report.php`), jamais en dur.
- La version de rapport enregistre l'objet de données (JSON) utilisé, le paramétrage RG-RPT-08 et la source de chaque donnée (`platform`, `import`, `compta_flow`).

### 8.5 Factures certifiées FNE (RG-FNE-01 à 05)
- La facture est d'abord créée en base (`invoices`, statut `draft`), numérotée par `SequenceService` (clé `invoice`), puis soumise à la certification par le Job `CertifyInvoice` (file `integrations`).
- Le PDF remis au client n'est produit **qu'après** certification, avec les éléments renvoyés (numéro FNE, QR de certification, cachet, mention / logo) injectés dans le modèle `facture` (`${fne_number}`, `${fne_qr}`, `${fne_stamp}`…).
- En mode `manual`, l'Action `RecordManualCertification` exige le numéro FNE (format vérifié par une expression paramétrable) et le PDF certifié déposé (contrôle du contenu PDF) ; le PDF déposé devient le document remis.
- Avoirs : `invoices.type = credit_note`, liée à la facture d'origine, même cycle de certification.

---

## 9. Statistiques et tableaux de bord

- Tables d'agrégats `stat_*` (05-BACKEND-SCHEMA §11) recalculées :
  - chaque nuit (02:00) pour l'année en cours ;
  - à la demande (bouton « Actualiser », limité à 1 fois / 10 min) ;
  - et de façon ciblée après les événements qui les modifient (paiement confirmé, adhésion validée, radiation), via un Job dédoublonné de 5 min (`ShouldBeUnique`).
- La période des tableaux de bord est une **dimension** des agrégats (jour, mois, année).
- Le CNCGA lit uniquement les tables `stat_*`, qui ne contiennent aucune donnée nominative.
- Les agrégats ne sont calculés que sur le portefeuille CGA (`regulatory()`, RG-STA-05). Le chiffre d'affaires retenu suit la règle de priorité RG-DATA-06 (Compta Flow, puis saisie / import).
- Les moyennes « position du CGA » ne sont calculées que si au moins 5 CGA y contribuent (RG-STA-04).
- Chaque réponse de statistique renvoie `coverage: { subscribed_cgas, total_cgas }` et `computed_at`.

---

## 10. Notifications et envois

- Laravel Notifications avec 4 canaux : `database` (dans la plateforme), `mail`, `sms`, `whatsapp`. Les canaux `sms` et `whatsapp` sont des classes maison.
- **SMS** : interface `SmsProvider` avec les pilotes :
  - `log` (développement) ;
  - `orange_ci` (API SMS d'Orange Côte d'Ivoire) ;
  - `http_generic` (agrégateur configurable : URL, clé, format).

  Le fournisseur définitif est choisi après chiffrage. Expéditeur alphanumérique « CGACONNECT » (à enregistrer auprès de l'opérateur).
- **Identifiants paramétrables (RG-NOT-05)** :
  - les identifiants SMS, WhatsApp et SMTP sont stockés dans la table `messaging_providers` (05-BACKEND-SCHEMA §14), chiffrés (cast `encrypted`), et saisis dans l'écran F-ADM-06 ;
  - le fichier `.env` ne contient **aucun** identifiant de fournisseur, seulement la clé de chiffrement de l'application ;
  - au démarrage d'un envoi, le pilote lit la configuration active en base (mise en cache selon `CACHE_STORE`, invalidée à l'enregistrement) ;
  - l'endpoint `POST /api/v1/platform/messaging/{channel}/test` envoie un message de test et renvoie la réponse brute du fournisseur ;
  - le transport e-mail Laravel est configuré dynamiquement à partir de la base (`Mail::build()` / transport personnalisé).
- **WhatsApp** : WhatsApp Business **Cloud API** (Meta), avec des modèles de messages pré-approuvés (`echeance_rappel`, `honoraires_impayes`, `formation_rappel`). Facturation à la conversation.
- **E-mail** : SMTP d'un fournisseur transactionnel (choisi au déploiement). Expéditeur `no-reply@<domaine>` avec SPF, DKIM et DMARC configurés.
- Toutes les notifications sont mises en file (`notifications`). Chaque envoi externe est écrit dans `message_logs` (statut, identifiant du fournisseur, coût).
- **Quotas SMS** par CGA et par mois (`sms_quotas`) : un envoi au-delà du quota est refusé avec le message « Quota épuisé ». Les codes de connexion sont hors quota.
- Plage d'envoi 7 h–20 h pour SMS et WhatsApp : en dehors, le message est différé au lendemain 7 h (`->delay()`).
- Préférences : `notification_preferences` (refus SMS / WhatsApp non essentiels). Chaque type de notification est déclaré `essential` ou non dans `config/notifications.php`.

---

## 10 bis. Intégrations externes (FNE, Compta Flow)

Principes communs : chaque intégration est une **interface** avec plusieurs pilotes ; la configuration est **par organisation**, stockée chiffrée en base (jamais dans `.env`), saisie dans un écran de paramétrage avec bouton « Tester » ; chaque appel sortant est journalisé (`activity_logs` + table de suivi propre, sans secret ni contenu sensible en clair) ; délais, nombre d'essais et intervalles sont variabilisés ; appels HTTP via le client Laravel avec délai maximal, vérification TLS obligatoire et **liste blanche des hôtes** (OWASP A10 / SSRF : l'URL saisie doit appartenir aux domaines autorisés de `config/integrations.php`).

### 10 bis.1 Certification FNE
- Interface `FneCertifier` : `certify(Invoice): FneResult`, `status(string $ref)`, `test(): bool`.
- Pilotes :
  - `manual` (défaut au lancement, aucune API) ;
  - `dgi_api` : API FNE de la DGI. Identifiants et URL par organisation (`invoicing_providers`). Le format exact des échanges sera intégré dès réception de la documentation et de l'environnement de test DGI ; le pilote est développé contre un **bouchon** (`FakeFneCertifier`) qui reproduit succès, rejet et indisponibilité ;
  - `sell_flow` : délégation à Sell Flow (si le centre l'utilise), même contrat.
- Réponse enregistrée dans `fne_certifications` (requête horodatée, statut, numéro, QR, cachet, message de rejet) ; nouvelle tentative par `CertifyInvoice` avec intervalle croissant (`fne.retry.tries`, `fne.retry.backoff_seconds`) ; alerte au gérant après le dernier échec (RG-FNE-04).
- Les reçus (RG-FNE-05) utilisent la même interface le jour où la normalisation RNE est confirmée (`fne.receipts_enabled`, défaut faux).

### 10 bis.2 Connecteur Compta Flow (F-INT-03, RG-DATA-06, RG-DATA-07)
- Interface `FlowConnector` (déjà prévue) avec le pilote `compta_flow` : `test()`, `fetchTurnover(ncc, year)`, `fetchTaxPayments(ncc, from, to)`.
- Configuration par organisation : `flow_connections` (URL, identifiants chiffrés, actif, fréquence).
- Job `SyncComptaFlow` (file `integrations`, planifié chaque nuit et à la demande, dédoublonné par organisation) :
  1. lit les dossiers actifs de l'organisation ;
  2. rapproche par NCC normalisé ; consigne les non rapprochés ;
  3. écrit les données dans les tables existantes (`member_revenues`, `obligation_payments` rattachés aux `obligation_instances`) avec `source = 'compta_flow'` et l'identifiant externe dans `external_references` ;
  4. en cas d'écart avec une valeur `platform` ou `import`, la valeur Flow remplace l'ancienne ; l'écart est écrit dans `audit_logs` (avant / après, source) et compté dans le rapport ;
  5. écrit le rapport dans `sync_runs` (lus, rapprochés, écarts, erreurs) et notifie le gérant en cas d'échec.
- Une valeur de source `compta_flow` est **en lecture seule** dans l'API (règle dans la Policy et l'Action, 409 `source_locked`).
- Le pilote est développé contre un bouchon (`FakeComptaFlow`) ; le branchement réel dépend de l'API fournie par DC-KNOWING (§15).

---

## 11. Tâches planifiées (Laravel Scheduler)

| Tâche | Fréquence | Effet |
|---|---|---|
| `obligations:generate` | chaque nuit 01:00 | Crée les échéances des 3 prochains mois (RG-OBL-04) |
| `fees:generate` | chaque nuit 01:15 | Crée les échéances d'honoraires de la période |
| `statuses:refresh` | chaque nuit 01:30 | Passe en « impayé » les honoraires à J+30 ; marque les échéances dépassées ; rapports en retard |
| `reminders:obligations` | chaque jour 07:00 | Rappels J-7 et J-1, échéances dépassées |
| `reminders:fees` | chaque jour 07:05 | Rappels d'honoraires impayés |
| `reminders:documents` | chaque jour 07:10 | Pièces en attente J+30 (puis tous les 30 j) |
| `reminders:reports` | chaque jour 07:15 | Rapports à J-15, J-5 et J+5 ; passage « hors délai réglementaire » à J+15 et alerte CNCGA (décalages : `report.reminder_offsets`, `report.legal_offset_days`) |
| `reminders:closing` | chaque jour 07:35 | Campagne de clôture : rappels de pièces aux clients, retards d'étape aux agents (RG-OBL-11) |
| `closing:open-campaign` | 2 janvier 00:45 (paramétrable) | Ouvre la campagne de clôture de l'exercice N-1 pour chaque CGA, avec le calendrier en vigueur |
| `fne:retry` | toutes les 15 min | Relance les certifications en attente (RG-FNE-04) |
| `flow:sync` | chaque nuit 00:30 | Synchronisation Compta Flow des organisations connectées |
| `reminders:dues` | chaque jour 07:20 | Relances de cotisation J+30, J+60, J+90 |
| `reminders:agreements` | chaque jour 07:25 | Agréments à 6 et 3 mois ; RSI / RNI du portefeuille CGA à J+60 et J+90 ; honoraires impayés > 3 mois (alertes seulement, aucun blocage automatique) |
| `reminders:concerns` | chaque jour 07:30 | Préoccupations sans réponse depuis 15 jours |
| `dues:open-year` | 1er janvier 00:30 | Crée la cotisation de l'année pour chaque CGA du registre |
| `trainings:waitlist` | toutes les 15 min | Promotion de la liste d'attente après désistement |
| `stats:refresh` | chaque nuit 02:00 | Recalcul des agrégats |
| `users:inactivity` | chaque jour 03:00 | Désactivation à 12 mois, suppression à 24 mois (après information) |
| `retention:purge` | chaque semaine | Purges et anonymisations selon PRD §7 |
| `members:radiation-expiry` | chaque jour 03:30 | Ferme l'accès des radiés après 6 mois |
| `backup:run` | chaque nuit 04:00 | Sauvegarde (§12.3) |

Toutes les tâches sont **idempotentes** : les relancer ne crée pas de doublon (contraintes d'unicité, voir 05-BACKEND-SCHEMA).

---

## 12. Environnements, déploiement, exploitation

### 12.1 Environnements

| Environnement | Usage | Données |
|---|---|---|
| `local` | Développement sur le poste (Laragon ou équivalent) : PHP 8.3, PostgreSQL 16 (extensions `pg_trgm`, `unaccent`, `citext`), LibreOffice, Node 20+ ; Redis facultatif ; e-mails capturés (pilote `log` ou Mailpit) ; files `database` | Jeu de démonstration (seeders) |
| `recette` | Validation par la personne chargée de la recette et les CGA pilotes | Données fictives, envois SMS désactivés (pilote `log`) |
| `production` | Exploitation | Réelles |

### 12.2 Intégration et déploiement continus
- Pipeline à chaque demande de fusion :
  - Pint, Larastan, Pest (avec PostgreSQL réel) ;
  - ESLint, `tsc`, Vitest ;
  - build du SPA ;
  - **test de cloisonnement** (§6.3).
- Fusion sur `main` : déploiement automatique en recette par script (`infra/deploy.sh` : récupération du code, `composer install --no-dev`, build du SPA, migrations `--force`, `config:cache`, `route:cache`, `queue:restart`).
- Étiquette `vX.Y.Z` : déploiement en production avec le même script.
- **Serveur (sans Docker)** : Linux (Ubuntu LTS recommandé), Nginx, PHP-FPM 8.3, PostgreSQL 16, LibreOffice, Supervisor pour les workers `queue:work` (un programme par file : `default`, `documents`, `notifications`, `imports`), cron pour `schedule:run`. Redis installé seulement si l'on bascule les files ou le cache.
- Migrations uniquement additives en production. Toute suppression de colonne passe par deux versions.

### 12.3 Exploitation
- **Sauvegarde** : `pg_dump` chiffré chaque nuit + archive chiffrée du dossier de stockage `storage/app/private` (incrémentale) ; rétention 30 jours (variabilisée) ; copie hors du serveur ; test de restauration chaque trimestre.
- **Supervision** :
  - Sentry (frontend et backend) ;
  - tableau Horizon si Redis, sinon écran « Files d'attente » de `/plateforme` (jobs en attente, échoués, relance) ;
  - point de santé `GET /api/health` (base, files, disque de stockage et espace libre, LibreOffice) surveillé toutes les minutes ;
  - alerte si une file a plus de 15 min de retard (seuil variabilisé).
- **Journaux** : JSON structurés, 30 jours.
- **Hébergement** : un serveur (4 vCPU, 8 Go de RAM, 100 Go SSD) suffit pour la première année. Le lieu d'hébergement est à fixer (point ouvert §14).
- **Maintenance** : jamais les 10 et 15 du mois ni les 5 derniers jours d'un trimestre.

### 12.4 Performance (cibles)

| Opération | Cible |
|---|---|
| Liste paginée (25 lignes) | < 300 ms côté serveur |
| Recherche d'adhérent | < 1 s |
| Tableau de bord | < 500 ms (lecture des agrégats) |
| Génération d'un document | < 10 s |
| Génération d'un rapport trimestriel (500 adhérents) | < 60 s |
| Import de 1 000 adhérents | < 2 min |

- Index sur toutes les clés étrangères et sur les colonnes filtrées (voir 05-BACKEND-SCHEMA).
- Les requêtes N+1 sont interdites (`Model::preventLazyLoading()` hors production).

---

## 13. Tests (stratégie)

> **Consigne ferme** : voir 06-IMPLEMENTATION-PLAN §0bis. Après chaque fonctionnalité, ses tests sont écrits, la suite complète de non-régression est rejouée et la couverture est mesurée. On ne passe pas à la fonctionnalité suivante tant que tout n'est pas vert.

| Niveau | Outil | Couverture minimale |
|---|---|---|
| Unitaires (règles) | Pest | Toutes les règles `RG-` calculatoires : numérotation (simple et double), statuts de paiement, prorata, score bancaire (4 critères), génération des échéances, report des jours fériés, agrégats du rapport (identiques entre rapport modernisé et classeur), résolution des droits (4 niveaux), dates de relance J-15 / J-5 / J+5 / J+15 et calendrier de clôture |
| Fonctionnels API | Pest (HTTP) | Chaque route : droits par rôle (matrice PRD §3.3), validations, codes d'erreur |
| Cloisonnement | Pest | Test parcourant toutes les routes (§6.3) — **bloquant** |
| Portefeuilles | Pest | Un dossier Cabinet n'apparaît dans aucune sortie réglementaire (rapport, export de compatibilité, `stat_*`) ; un dossier « Accessoire » du portefeuille CGA y apparaît sans son apporteur ; aucun dossier CGA sans contrat ; aucun apporteur sur un dossier Cabinet ; l'apporteur n'apparaît dans aucune réponse sans la permission ni dans aucun document officiel ; aucune radiation créée hors action utilisateur — **bloquant** |
| Intégrations | Pest + bouchons | FNE : succès, rejet, indisponibilité puis reprise, mode manuel ; Compta Flow : rapprochement, écart (Flow fait foi), valeur verrouillée, échec. Aucun appel réseau réel en CI |
| Documents | Pest | Génération réelle avec LibreOffice en CI ; contrôle des variables non remplacées (`${` absent du résultat) |
| Gestion des erreurs | Pest | Pour chaque contrôleur : une exception simulée de chaque type (§4.4) produit le bon code HTTP, la bonne réponse et une ligne de journal |
| Journalisation | Pest | Chaque route produit une ligne `activity_logs` (test générique parcourant toutes les routes) ; chaque modification produit une ligne `audit_logs` avec avant / après ; aucun secret en clair dans les journaux |
| Sécurité (OWASP, données du frontend) | Pest + analyse statique + OWASP ZAP | Pour chaque route : requête avec champs non autorisés (ex. `organization_id`, `status`, `amount`, `membership_number`) → ignorés ou refusés ; identifiant d'un autre CGA → 404 ; valeurs hors format ou trop longues → 422 ; tri ou filtre hors liste blanche → refusé ; fichier déguisé (extension trompeuse) → refusé ; injection SQL et XSS de base sur les champs texte → sans effet ; `composer audit` / `npm audit` bloquants ; scan ZAP sans alerte haute en fin de lot |
| Variabilisation | Pest + analyse statique | Aucune utilisation de `env()` hors `config/` ; toute clé `settings` lue est déclarée dans le registre |
| Bascule de configuration | Pest | La suite passe avec `QUEUE_CONNECTION=database` ; un job de CI hebdomadaire la rejoue avec `redis` |
| Composants | Vitest + Testing Library | Formulaires (validation zod, erreurs 422 affichées), tableaux, `StatusBadge` |
| Parcours | Playwright | Les 10 parcours critiques du 03-APPFLOW §8, en recette |

Chaque règle `RG-xxx` a au moins un test dont le nom contient son identifiant (ex. `it('RG-ADH-03 attribue un numéro continu sans trou')`).

**Seuils de couverture** (mesurés en CI, une demande de fusion sous le seuil est refusée) :

| Périmètre | Outil | Seuil minimal |
|---|---|---|
| Backend — tout `app/` | Pest `--coverage` (PCOV) | 80 % des lignes |
| Backend — `app/Domain/*/Actions` et `Policies` | Pest `--coverage` | 90 % des lignes |
| Frontend — `src/features`, `src/lib` | Vitest `--coverage` (v8) | 70 % des lignes |
| Fonctionnalité nouvelle ou modifiée | Rapport de couverture des fichiers touchés | 85 % des lignes, aucune règle `RG-` sans test |

Commandes de référence :
- `composer test` = `pest --parallel --coverage --min=80` ;
- `npm run test:ci` = `vitest run --coverage` (seuils dans `vitest.config.ts`) ;
- `npm run e2e` = Playwright sur les parcours du 03-APPFLOW §8.

---

## 14. Registre des décisions techniques

| ID | Décision | Raison | Alternatives écartées |
|---|---|---|---|
| T-01 | SPA React sur le même domaine que l'API, authentification Sanctum par cookie | Sécurité (pas de jeton en stockage navigateur), simplicité | Jetons JWT, Inertia.js (écarté par la stack imposée React séparée) |
| T-02 | Monolithe modulaire, base unique, cloisonnement applicatif par `organization_id` + test bloquant | Taille de l'équipe, 111 CGA | Une base par CGA (exploitation trop lourde) ; RLS PostgreSQL (complexité ; réévaluée après la V1, §15) |
| T-03 | Rôles fixes ; catalogue de permissions dans le code ; attribution rôle → permissions en base, paramétrable au niveau réseau et CGA, avec permissions verrouillées | Flexibilité demandée par le Directeur (10/10/2026) sans perdre les garanties de confidentialité | spatie/laravel-permission (rôles libres, pas de notion de verrou ni de double niveau réseau / CGA) |
| T-04 | Recherche PostgreSQL trigram | Volume < 100 000 lignes | Meilisearch |
| T-05 | DOCX → PDF via LibreOffice installé sur le serveur, derrière l'interface `PdfConverter` | Fidélité aux modèles normés DGI ; pas de Docker au démarrage | Dompdf / génération HTML (mise en page différente des modèles) ; Gotenberg (nécessite Docker, possible plus tard) |
| T-06 | Classeur officiel rempli à partir du modèle, plan B XML — devenu export de compatibilité (T-21) | Le classeur faisait foi avant le compte rendu du 14/10/2026 ; reste exigé tant que le SP-CGA n'a pas validé le rapport modernisé | Reconstruction du classeur par code |
| T-07 | Statistiques par tables d'agrégats | Le CNCGA ne lit jamais de données nominatives ; performance | Requêtes directes à la volée |
| T-08 | Fournisseurs SMS / WhatsApp derrière une interface | Fournisseur non choisi | Couplage à un fournisseur |
| T-09 | Pas de paiement en ligne. Intégration Flow limitée à la **lecture Compta Flow** (révisé le 15/10/2026, CR-15) | Périmètre PRD §2.2 | Écriture vers Flow, Sell Flow et RH Flow (évolutions) |
| T-10 | Pas de Docker au démarrage ; installation classique sur serveur | Décision du 10/10/2026 | Docker Compose (reporté) |
| T-11 | Stockage sur le disque du serveur via Flysystem, bascule S3 par configuration | Décision du 10/10/2026 | S3 / MinIO dès le départ |
| T-12 | Files, cache et sessions basculables `database` ↔ `redis` par configuration | Décision du 10/10/2026 : démarrer sans Redis | Redis obligatoire |
| T-13 | `try/catch` obligatoire dans les contrôleurs via `BaseApiController::handle()` | Règle du 10/10/2026 | Gestionnaire global seul |
| T-14 | Journalisation de toute action (lectures comprises) dans `activity_logs` + `audit_logs` | Règle stricte du 10/10/2026 | Journal des seules écritures |
| T-15 | Variabilisation de toute valeur susceptible de changer (§7.6) | Règle stricte du 10/10/2026 | — |
| T-16 | OWASP Top 10 (2025) respecté dans chaque fonctionnalité ; aucune confiance dans les données du frontend (§6.7) | Règle stricte du 10/10/2026 | — |
| T-17 | Portefeuille porté par une colonne `members.portfolio` + scope `regulatory()` pour toutes les sorties réglementaires | Un seul modèle de dossier, exclusion garantie par un point unique et testée | Deux tables distinctes (duplication des fonctions) |
| T-18 | Habilitations par utilisateur en table `user_permission_overrides`, résolues après les verrous et avant les surcharges de rôle | Compte rendu du 14/10/2026 (CR-13) | Création de rôles libres (perte de la matrice de référence) |
| T-19 | Certification FNE derrière l'interface `FneCertifier` (pilotes `manual`, `dgi_api`, `sell_flow`), mode manuel par défaut | API DGI non encore disponible pour le projet ; mise en conformité immédiate possible | Attendre l'API (bloquant) ; facturation hors plateforme |
| T-20 | Connecteur Compta Flow en lecture, synchronisation par Job, Compta Flow fait foi en cas d'écart | CR-08, CR-15 | Saisie manuelle seule ; écriture bidirectionnelle |
| T-21 | Rapport modernisé en DOCX avec graphiques natifs PhpWord → PDF via LibreOffice ; repli en images PNG | Réutilise la chaîne documentaire existante, pas de navigateur sans tête à installer | Génération HTML + navigateur sans tête (dépendance lourde sur le serveur) |
| T-22 | Exclusivité d'adhésion bloquante, vérifiée dans la transaction avec verrou consultatif sur le NCC | CR-07 ; empêche la double inscription simultanée | Simple alerte (décision antérieure, remplacée) |
| T-23 | Bascule CGA → Cabinet réalisée par la radiation (pas d'Action distincte), contrainte base « contrat ↔ portefeuille CGA » | D-02, D-03, D-04 : un seul chemin de sortie du portefeuille CGA, toujours manuel | Action de bascule directe (risque de sortie sans radiation dans le rapport) |

## 15. Points techniques ouverts

| Point | Défaut appliqué | Échéance |
|---|---|---|
| Lieu d'hébergement (Côte d'Ivoire, Afrique, autre) | Serveur unique (installation classique, sans Docker) chez un hébergeur à choisir ; données sauvegardées hors du serveur | Avant la mise en production |
| Passage à Docker, S3 ou Redis | Non utilisés au démarrage ; bascule par configuration le moment venu | Après la V1, selon la charge |
| Fournisseur SMS et tarif | Pilote `log` en recette | Avant le Lot 6 |
| Compte WhatsApp Business et modèles validés par Meta | Canal désactivé tant que non configuré | Avant le Lot 6 |
| Nom de domaine | `cgaconnect.ci` (exemple) | Lot 0 |
| Sécurité au niveau des lignes (RLS PostgreSQL) en complément du cloisonnement applicatif | Non activée en V1 | Revue après la V1 |
| API Compta Flow (documentation, authentification, environnement de test) | Pilote développé contre un bouchon ; import Excel en attendant | Avant le lot Compta Flow |
| API FNE de la DGI (documentation, environnement de test, identifiants par CGA) | Mode `manual` en production | Dès obtention ; avant la généralisation |
| Rendu des graphiques PhpWord par LibreOffice | Validation au Lot 0 ; repli PNG | Lot 0 |
| Sell Flow, RH Flow | Hors périmètre | Après la V1 |
