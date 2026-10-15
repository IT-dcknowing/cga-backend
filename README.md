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

## Démarrer

Les instructions de démarrage du backend (`backend/`) et du frontend (`frontend/`) arrivent avec les étapes 0.3 et 0.4 du plan.
