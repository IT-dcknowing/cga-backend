# Installer le serveur de recette

Installation classique, sans Docker ni S3 (02-TRD §2, §12.2, T-10, T-11). La production s'installe de la même façon, avec `DEPLOY_ENV=production`, ses propres variables et un déploiement par étiquette `vX.Y.Z`.

Un hébergement mutualisé (cPanel) ne convient pas : il faut Supervisor pour les workers, un cron chaque minute, LibreOffice et PostgreSQL 16.

## Contenu de `infra/`

| Fichier | Rôle | Emplacement sur le serveur |
|---|---|---|
| `infra.env.example` | Toutes les valeurs propres au serveur : domaine, dossiers, workers, sauvegardes… | `/etc/cga-connect/infra.env` |
| `scripts/render.sh` | Produit les fichiers de configuration depuis les gabarits `*.template` et `infra.env` | — |
| `nginx/cga-connect.conf.template` | SPA et API sur le même domaine, TLS, en-têtes de sécurité, limite du point de santé | `/etc/nginx/sites-available/` |
| `nginx/cga-connect-php.conf.template` | Passage des requêtes `/api` et `/sanctum` à PHP-FPM | `/etc/nginx/snippets/` |
| `php/cga-connect-pool.conf.template` | Groupe PHP-FPM dédié (utilisateur `APP_USER`) | `/etc/php/8.3/fpm/pool.d/` |
| `php/cga-connect.ini.template` | Réglages PHP (OPcache…) | `/etc/php/8.3/{fpm,cli}/conf.d/` |
| `supervisor/cga-connect-workers.conf.template` | Un programme par file : `default`, `documents`, `notifications`, `imports` | `/etc/supervisor/conf.d/` |
| `cron/cga-connect.template` | Planificateur Laravel chaque minute, sauvegarde de nuit | `/etc/cron.d/cga-connect` |
| `logrotate/cga-connect.template` | Rotation du journal des sauvegardes | `/etc/logrotate.d/cga-connect` |
| `scripts/deploy.sh` | Déploiement d'une version | — |
| `scripts/backup.sh` | Sauvegarde chiffrée de la base et du stockage | — |

## 1. Serveur et paquets

Ubuntu 24.04 LTS ; 4 vCPU, 8 Go de RAM, 100 Go SSD suffisent la première année (02-TRD §12.3). Ubuntu 24.04 fournit directement PHP 8.3 et PostgreSQL 16.

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx postgresql-16 supervisor git unzip curl gnupg rsync gettext-base \
    php8.3-fpm php8.3-cli php8.3-pgsql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip \
    php8.3-intl php8.3-gd php8.3-bcmath php8.3-opcache composer \
    libreoffice-writer-nogui libreoffice-calc-nogui fonts-dejavu fonts-liberation fonts-crosextra-carlito \
    certbot python3-certbot-nginx

# Node 20 ou plus (build du SPA au déploiement) : dépôt NodeSource
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
```

Vérifier : `php -v` (8.3), `psql --version` (16), `soffice --version` (7.6 ou plus), `node -v` (20 ou plus).

## 2. Utilisateur, dossiers et variables

```bash
sudo adduser --system --group --home /var/www/cga-connect --shell /bin/bash cga
sudo mkdir -p /var/www/cga-connect/{releases,shared} /var/log/cga-connect /etc/cga-connect /var/www/letsencrypt
sudo chown -R cga:cga /var/www/cga-connect /var/log/cga-connect

sudo cp infra/infra.env.example /etc/cga-connect/infra.env    # depuis une copie du dépôt
sudo chmod 600 /etc/cga-connect/infra.env
sudo nano /etc/cga-connect/infra.env                           # DOMAIN, REPO_URL, BACKUP_* …
```

`deploy.sh` lit `infra.env` sous l'utilisateur `cga` : lui donner la lecture (`sudo chgrp cga /etc/cga-connect/infra.env && sudo chmod 640 /etc/cga-connect/infra.env`).

L'utilisateur `cga` recharge PHP-FPM à chaque déploiement : `sudo visudo -f /etc/sudoers.d/cga-connect`, puis
`cga ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.3-fpm`.

Accès au dépôt : une clé de déploiement en lecture seule (`sudo -u cga ssh-keygen -t ed25519`, clé publique déclarée dans le dépôt).

## 3. PostgreSQL

```bash
sudo -u postgres psql <<'SQL'
CREATE ROLE cga_connect LOGIN PASSWORD 'mot-de-passe-fort';
CREATE DATABASE cga_plateforme OWNER cga_connect;
\c cga_plateforme
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS unaccent;
CREATE EXTENSION IF NOT EXISTS citext;
SQL
```

Le nom de la base est `DB_NAME` dans `infra.env` (sauvegardes) et `DB_DATABASE` dans le `.env` de Laravel.

## 4. Fichiers de configuration

```bash
sudo infra/scripts/render.sh /etc/cga-connect/infra.env /etc/cga-connect/rendered
R=/etc/cga-connect/rendered

sudo ln -sf $R/nginx/cga-connect.conf /etc/nginx/sites-enabled/cga-connect.conf
sudo ln -sf $R/nginx/cga-connect-php.conf /etc/nginx/snippets/cga-connect-php.conf
sudo ln -sf $R/php/cga-connect-pool.conf /etc/php/8.3/fpm/pool.d/cga-connect.conf
sudo ln -sf $R/php/cga-connect.ini /etc/php/8.3/fpm/conf.d/90-cga-connect.ini
sudo ln -sf $R/php/cga-connect.ini /etc/php/8.3/cli/conf.d/90-cga-connect.ini
sudo ln -sf $R/supervisor/cga-connect-workers.conf /etc/supervisor/conf.d/cga-connect-workers.conf
sudo install -m 644 $R/cron/cga-connect /etc/cron.d/cga-connect        # cron refuse les liens
sudo install -m 644 $R/logrotate/cga-connect /etc/logrotate.d/cga-connect
sudo rm -f /etc/nginx/sites-enabled/default
```

Après toute modification de `infra.env` : relancer `render.sh`, puis recharger le service concerné (`sudo nginx -t && sudo systemctl reload nginx`, `sudo systemctl reload php8.3-fpm`, `sudo supervisorctl reread && sudo supervisorctl update`, `sudo install …` pour le cron).

## 5. Certificat TLS

Avant le premier certificat, Nginx ne peut pas charger le bloc HTTPS : commenter temporairement le second bloc `server` de `/etc/cga-connect/rendered/nginx/cga-connect.conf`, puis :

```bash
sudo nginx -t && sudo systemctl reload nginx
sudo certbot certonly --webroot -w /var/www/letsencrypt -d recette.cgaconnect.ci
sudo infra/scripts/render.sh /etc/cga-connect/infra.env /etc/cga-connect/rendered   # rétablit le bloc HTTPS
sudo nginx -t && sudo systemctl reload nginx
```

Le renouvellement est automatique (minuteur `certbot.timer`) ; ajouter `--deploy-hook "systemctl reload nginx"` à la commande `certonly` pour recharger Nginx après chaque renouvellement.

## 6. Configuration de Laravel et stockage partagés

```bash
sudo -u cga mkdir -p /var/www/cga-connect/shared/storage/{app/private,framework/{cache/data,sessions,views},logs}
sudo -u cga cp backend/.env.example /var/www/cga-connect/shared/.env
sudo -u cga chmod 600 /var/www/cga-connect/shared/.env
sudo -u cga nano /var/www/cga-connect/shared/.env
```

Valeurs à régler dans le `.env` de recette :

```
APP_ENV=recette
APP_DEBUG=false
APP_URL=https://recette.cgaconnect.ci
APP_KEY=                       # php artisan key:generate --show, depuis une version déployée
DB_DATABASE=cga_plateforme
DB_USERNAME=cga_connect
DB_PASSWORD=…
LIBREOFFICE_BIN=soffice
MAIL_MAILER=log                # recette : aucun envoi réel (02-TRD §12.1)
LOG_CHANNEL=daily
SESSION_SECURE_COOKIE=true     # cookie de session en HTTPS seulement
SANCTUM_STATEFUL_DOMAINS=      # vide : le SPA est sur le domaine de APP_URL
CORS_ALLOWED_ORIGINS=          # vide : aucune origine étrangère
SENTRY_LARAVEL_DSN=…           # projet Sentry de la recette ; vide = désactivé
SENTRY_ENVIRONMENT=recette
SEED_DEMO=true                 # recette : comptes fictifs de démonstration (jamais en production)
SEED_PLATFORM_ADMIN_PHONE=+225…  # premier administrateur de la plateforme
SEED_PLATFORM_ADMIN_EMAIL=…
```

La documentation de l'API (`/docs/api`) n'est ouverte qu'en environnement `local` ; elle n'est pas servie par Nginx.

Bascule vers Redis (plus tard, sans changer le code, 02-TRD T-12) : `sudo apt install -y redis-server php8.3-redis`, puis `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `SESSION_DRIVER=redis` et `REDIS_HOST` / `REDIS_PASSWORD` dans ce `.env`. Horizon ne s'installe qu'à ce moment-là, et les workers Supervisor restent valables.

Les identifiants SMS, WhatsApp, e-mail, FNE et Compta Flow ne vont jamais dans `.env` : ils se saisissent dans les écrans de l'application (AGENTS.md).

## 7. Premier déploiement

```bash
sudo -u cga git clone git@github.com:organisation/cga-connect.git /var/www/cga-connect/repo-initial
sudo -u cga INFRA_ENV=/etc/cga-connect/infra.env /var/www/cga-connect/repo-initial/infra/scripts/deploy.sh main
```

Pour ce premier lancement, le script vient de cette copie du dépôt (`repo-initial`, supprimable ensuite) ; les suivants utilisent `/var/www/cga-connect/current/infra/scripts/deploy.sh`. La CI (étape 0.5) lance ce même script à chaque fusion sur `main`.

Ensuite :

```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl status   # 5 workers RUNNING
curl -s https://recette.cgaconnect.ci/api/health                                      # "status": "ok"
```

Le point de santé est à surveiller chaque minute par un service externe (02-TRD §12.3) : une réponse autre que 200 doit alerter. Il est limité à `HEALTH_RATE_LIMIT` requêtes par IP.

Données de base, puis lien d'activation du premier administrateur de la plateforme, à lui transmettre (valable `invitation.validity_days` jours ; relancer la commande en émet un nouveau et annule le précédent) :

```bash
sudo -u cga php8.3 /var/www/cga-connect/current/backend/artisan db:seed --force
sudo -u cga php8.3 /var/www/cga-connect/current/backend/artisan platform:invite-admin
```

## 8. Retour arrière

Les `KEEP_RELEASES` dernières versions restent dans `releases/` (le fichier `REVISION` donne leur commit) :

```bash
cd /var/www/cga-connect
ls -1 releases/
sudo -u cga ln -sfn /var/www/cga-connect/releases/<version précédente> current.next
sudo -u cga mv -Tf current.next current
sudo systemctl reload php8.3-fpm
sudo -u cga php8.3 current/backend/artisan queue:restart
```

Les migrations étant uniquement additives (02-TRD §12.2), l'ancienne version fonctionne sur la base migrée.

## 9. Sauvegardes et restauration

`backup.sh` tourne chaque nuit (`BACKUP_CRON`, 04:00) sous root :

- la base : `pg_dump` chiffré, dans `BACKUP_DIR/db/` ;
- le stockage `shared/storage/app/private` : une archive complète le jour `BACKUP_FULL_WEEKDAY` puis des archives incrémentales, chiffrées, dans `BACKUP_DIR/files/<jeu>/` ;
- rétention `BACKUP_RETENTION_DAYS` jours, puis copie vers `BACKUP_REMOTE` (rsync sur SSH, clé de root).

Le chiffrement utilise une clé publique GPG. La clé privée est créée **hors du serveur** et conservée en lieu sûr par deux personnes :

```bash
# sur un poste de confiance
gpg --quick-generate-key sauvegardes@cgaconnect.ci rsa4096 encrypt never
gpg --armor --export sauvegardes@cgaconnect.ci > sauvegardes.pub.asc
# sur le serveur
sudo gpg --import sauvegardes.pub.asc
sudo INFRA_ENV=/etc/cga-connect/infra.env /var/www/cga-connect/current/infra/scripts/backup.sh   # essai
```

Journal : `/var/log/cga-connect/backup.log` et `journalctl -t cga-backup`. Un message « ALERTE » signale une sauvegarde sans copie hors du serveur.

**Test de restauration chaque trimestre** (02-TRD §12.3), sur un poste qui détient la clé privée :

```bash
# Base, dans une base de test vide
createdb cga_restauration
gpg --decrypt cga_plateforme-AAAAMMJJ-HHMMSS.dump.gpg | pg_restore --no-owner --dbname=cga_restauration

# Stockage : l'archive complète du jeu, puis ses archives incrémentales dans l'ordre
mkdir restauration
for archive in $(ls <jeu>/*.tar.gz.gpg | sort); do
    gpg --decrypt "$archive" | tar --extract --gzip --listed-incremental=/dev/null --directory=restauration
done
```

Consigner la date, la sauvegarde testée et le résultat.

## 10. Exploitation

- Maintenance : jamais les 10 et 15 du mois, ni les 5 derniers jours d'un trimestre (02-TRD §12.3) ; un déploiement de production qui migre la base suit ce calendrier.
- Espace disque : le point de santé passe en défaut (`low_space`) au-delà du paramètre `files.disk_alert_percent` (80 % par défaut).
- Redis, S3 et Horizon ne sont pas installés au démarrage ; la bascule se fera par configuration (`QUEUE_CONNECTION`, `CACHE_STORE`, `SESSION_DRIVER`, `FILESYSTEM_DISK`), sans changer le code (02-TRD §2).
