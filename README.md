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
| LibreOffice | 7.6 ou plus | Conversion PDF ; sous Windows, `LIBREOFFICE_BIN` vise `soffice.com` (programme console), pas `soffice.exe` |
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
| `composer test` | Tests sur PostgreSQL (base `cga_plateforme_test`) avec couverture ; échoue sous 80 % des lignes |
| `vendor/bin/pint` | Mise en forme du code PHP |

Le serveur de recette (Nginx, PHP-FPM, Supervisor, cron, déploiement, sauvegardes) : [`infra/README.md`](infra/README.md).
