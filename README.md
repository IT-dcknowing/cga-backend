# CGA Connect

Plateforme du réseau des Centres de Gestion Agréés : espaces CNCGA, CGA et adhérent, plus la page publique de vérification des documents.

## Organisation du dépôt

| Dossier | Contenu |
|---|---|
| `backend/` | API Laravel (`/api/v1`) |
| `frontend/` | Application React (SPA), servie sur le même domaine que l'API |
| `infra/` | Scripts de déploiement et configurations du serveur |
| `docs/` | Spécifications : PRD, TRD, parcours, charte, schéma de données, plan d'implémentation |

Les règles de travail sont dans [`AGENTS.md`](AGENTS.md) (copie : `CLAUDE.md`). Les documents de `docs/` font foi ; l'ordre de construction est celui de [`docs/06-IMPLEMENTATION-PLAN.md`](docs/06-IMPLEMENTATION-PLAN.md), une branche par étape.

## Prérequis

- PHP 8.3, Composer, PostgreSQL 16 (extensions `pg_trgm`, `unaccent`, `citext`), LibreOffice
- Node 20 ou plus

## Démarrer le frontend

```bash
cd frontend
npm install
npm run dev        # http://localhost:5173 ; /api et /sanctum sont relayés vers http://127.0.0.1:8000
```

Pour viser une autre API en développement : variable `VITE_API_ORIGIN` (ex. `VITE_API_ORIGIN=http://127.0.0.1:8001 npm run dev`).

| Commande | Rôle |
|---|---|
| `npm run test:ci` | Tests (Vitest) avec couverture ; échoue sous 70 % des lignes |
| `npm run lint` · `npm run typecheck` · `npm run format:check` | ESLint, TypeScript, Prettier |
| `npm run build` | Construction de production dans `dist/` |
| `npm run e2e` | Parcours Playwright (navigateur à installer une fois : `npx playwright install chromium`, ou `PLAYWRIGHT_CHANNEL=chrome` pour le Chrome du poste) |
| `npm run gen:api` | Types TypeScript de l'API, depuis l'OpenAPI du backend lancé |

## Démarrer le backend

Poste local sans Docker (02-TRD §12.1), avec Laragon ou une installation directe :

| Outil | Version | Remarque |
|---|---|---|
| PHP | 8.3 | Extensions `pdo_pgsql`, `pgsql`, `mbstring`, `intl`, `zip`, `gd`, `bcmath`, `fileinfo`, `openssl`, `curl` ; **PCOV** pour la couverture des tests |
| PostgreSQL | 16 ou plus | Extensions `pg_trgm`, `unaccent`, `citext`, fournies avec PostgreSQL |
| LibreOffice | 7.6 ou plus | Conversion PDF ; sous Windows, `LIBREOFFICE_BIN` vise `soffice.com` (programme console), pas `soffice.exe`. Sans droits administrateur, extraire l'MSI officiel : `msiexec /a LibreOffice_<version>_Win_x86-64.msi /qn TARGETDIR="%LOCALAPPDATA%\Programs\LibreOffice"` |
| Composer | 2 | |

Créer les bases une fois, en tant que `postgres` :

```sql
ALTER ROLE cga_user CREATEDB;   -- ou CREATE ROLE cga_user LOGIN PASSWORD '…' CREATEDB;
CREATE DATABASE cga_plateforme OWNER cga_user;
CREATE DATABASE cga_plateforme_test OWNER cga_user;
```

```bash
cd backend
composer install
cp .env.example .env        # renseigner DB_PASSWORD et LIBREOFFICE_BIN
php artisan key:generate
php artisan migrate
php artisan serve           # http://localhost:8000 ; composer dev lance aussi un worker des files
```

Contrôle : `curl http://localhost:8000/api/health` répond 200 et `"status": "ok"` quand la base, les files d'attente, le disque de stockage et LibreOffice sont bons. Sinon, il répond 503 et nomme le contrôle en défaut avec un code (`unavailable`, `not_writable`, `low_space`) ; le détail technique n'est que dans `storage/logs/laravel.log`. Le disque est en défaut au-delà du paramètre `files.disk_alert_percent` (80 % d'occupation par défaut, `config/cga.php`).

| Commande | Rôle |
|---|---|
| `composer test` | Tests Pest en parallèle sur PostgreSQL (une base `cga_plateforme_test_*` par processus) avec couverture ; échoue sous 80 % des lignes |
| `composer analyse` | Analyse statique Larastan, niveau 6 (`env()` refusé hors de `config/`) |
| `composer lint` | Contrôle de la mise en forme (Pint) ; `vendor/bin/pint` la corrige |

Choix du socle (02-TRD §2, §4.2, §6) :

- **Connexion du SPA** : Sanctum en mode SPA, par le cookie de session `HttpOnly`, `Secure` (sauf sur le poste local en http) et `SameSite=Lax`, avec le jeton CSRF ; aucun jeton d'API. Le SPA demande d'abord `GET /sanctum/csrf-cookie`. Sur le poste local, `SANCTUM_STATEFUL_DOMAINS` et `CORS_ALLOWED_ORIGINS` nomment le serveur Vite ; en recette et en production, le SPA est sur le même domaine et ces variables restent vides.
- **Documentation de l'API** : `http://localhost:8000/docs/api` (Scramble), et le schéma OpenAPI sur `/docs/api.json`, dont `npm run gen:api` tire les types du frontend. Fermée (403) hors de l'environnement `local`.
- **Fuseau et langue** : `Africa/Abidjan`, français (`lang/fr`), y compris les messages de validation.
- **Fichiers** : disque `private` (`storage/app/private`, ou `FILES_ROOT`), jamais servi par le web ; les disques `local` et `public` de Laravel y sont redirigés.
- **Files, cache, sessions** : sur la base (`database`) ; la bascule vers Redis se fait par `QUEUE_CONNECTION`, `CACHE_STORE` et `SESSION_DRIVER` (extension PHP `redis` et serveur Redis 7 requis). La suite est rejouée sur Redis par la CI.
- **Erreurs** : envoyées à Sentry quand `SENTRY_LARAVEL_DSN` est renseigné, sans aucune donnée personnelle.

Le serveur de recette (Nginx, PHP-FPM, Supervisor, cron, déploiement, sauvegardes) : [`infra/README.md`](infra/README.md).

## Intégration continue

Le pipeline GitHub Actions (`.github/workflows/ci.yml`, 02-TRD §12.2) tourne à chaque demande de fusion et à chaque fusion sur `main` :

| Tâche | Contrôles, tous bloquants |
|---|---|
| `backend` | `composer audit` ; Pint ; Larastan niveau 6 avec les règles de sécurité du projet (`$request->all()` et données non validées, SQL brut non constant, `env()` hors `config/`) ; Pest en parallèle sur PostgreSQL 16, files, cache et sessions sur la base ; couverture globale ≥ 80 %, Actions et Policies ≥ 90 %, chaque fichier modifié ≥ 85 % (`tools/coverage-gate.php`) |
| `frontend` | `npm audit --audit-level=high` ; ESLint ; Prettier ; `tsc` ; Vitest avec seuil de couverture ; build du SPA |
| `deploy-recette` | Fusion sur `main` : `infra/scripts/deploy.sh` sur le serveur de recette |
| `deploy-production` | Étiquette `vX.Y.Z` : même script sur la production, après approbation |

Chaque semaine (`.github/workflows/redis-weekly.yml`), la suite complète est rejouée avec les files, le cache et les sessions sur Redis 7 (02-TRD §13, T-12).

À régler sur GitHub une fois le dépôt créé :

- **Protection de `main`** : demandes de fusion obligatoires, statuts requis « Backend » et « Frontend », revue croisée BE ↔ FE (06-IMPLEMENTATION-PLAN §2).
- **Déploiement en recette** : variable de dépôt `RECETTE_DEPLOY=true` ; secrets de l'environnement `recette` : `RECETTE_HOST`, `RECETTE_USER` (l'utilisateur `cga`), `RECETTE_SSH_KEY` (clé privée dédiée, sa clé publique dans `~cga/.ssh/authorized_keys`), `RECETTE_KNOWN_HOSTS` (`ssh-keyscan` du serveur).
- **Production** : variable `PRODUCTION_DEPLOY=true`, secrets `PRODUCTION_*` équivalents, et une approbation obligatoire sur l'environnement `production`.
