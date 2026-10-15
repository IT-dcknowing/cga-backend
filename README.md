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

Les instructions arrivent avec les étapes 0.2 et 0.3 du plan.
