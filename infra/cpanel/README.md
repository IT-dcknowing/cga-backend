# Recette sur un hébergement cPanel

Variante **de recette seulement** de l'installation décrite dans `infra/README.md`, pour un hébergement mutualisé cPanel (décision 0011). La production reste sur un serveur dédié (Ubuntu 24.04, `infra/scripts/deploy.sh`).

## Ce qui change par rapport au serveur dédié

| Serveur dédié (`infra/README.md`) | cPanel (ce dossier) |
|---|---|
| Nginx | Apache : `.htaccess` produit par `install.sh` (mêmes règles et en-têtes) |
| Supervisor, un worker par file | un cron chaque minute : `queue:work --stop-when-empty`, sous verrou `flock` |
| LibreOffice : PDF | **absent : aucun PDF** ; le contrôle `libreoffice` du point de santé est en échec, c'est attendu |
| Build du SPA sur le serveur | SPA construit sur le poste (`build-package.sh`), le serveur n'a pas Node |
| `systemctl reload php8.3-fpm` | sans objet ; `queue:restart` seulement |
| PostgreSQL 16 | PostgreSQL de l'hébergeur, 13 ou plus (extensions `pg_trgm`, `unaccent`, `citext` créées par les migrations) |

Le reste est identique : une version par dossier `releases/<date>`, configuration et stockage partagés (`shared/.env`, `shared/storage`), bascule du lien `current` une fois tout prêt, migrations additives, envois en journal seulement (`MAIL_MAILER=log`), comptes de démonstration (`SEED_DEMO=true`) avec un mot de passe propre au serveur.

## Prérequis dans cPanel

- PHP 8.3 avec `pdo_pgsql`, `pgsql`, `intl`, `zip`, `gd`, `bcmath`, `mbstring`, `curl`, `fileinfo`, `sodium`, en ligne de commande **et** pour le domaine (MultiPHP Manager) ;
- Composer, le Terminal (ou SSH) et les tâches cron ;
- une base PostgreSQL **dédiée** et un utilisateur qui y a tous les droits ;
- un domaine dont le dossier public est vide.

## 1. Construire le paquet (sur le poste)

```bash
cp infra/cpanel/cpanel.env.example ~/cpanel.env     # puis renseigner DOMAIN, DOCROOT, DB_DATABASE, DB_USERNAME
infra/cpanel/build-package.sh ~/cpanel.env ~/Desktop
```

Le dépôt doit être propre : le paquet ne contient que la version commitée. Il ne contient aucun mot de passe.

## 2. Installer ou mettre à jour (sur le serveur)

1. cPanel > Gestionnaire de fichiers : déposer `cga-connect-recette-<version>.tar.gz` dans le dossier personnel.
2. Terminal :

```bash
cd ~ && rm -rf cga-connect-recette && tar -xzf cga-connect-recette-<version>.tar.gz && bash cga-connect-recette/install.sh
```

À la première installation, le script demande le mot de passe de la base (saisie invisible) et l'écrit dans `~/cga-connect/shared/.env` (droits 600). Il refuse une base qui sert déjà à une autre application. Il ajoute ses deux lignes au crontab sans toucher aux autres (copie dans `~/cga-connect/crontab-avant-<date>.bak`). Il garde le bloc PHP que cPanel écrit dans `.htaccess`.

En fin d'installation, il contrôle le point de santé, l'interface et la connexion d'un compte de démonstration. Mot de passe de ces comptes : `grep SEED_DEMO_PASSWORD ~/cga-connect/shared/.env`.

Mot de passe de la base changé dans cPanel : `bash cga-connect-recette/install.sh --mot-de-passe`.

Journal des installations : `~/cga-connect/deploy.log` ; journaux de Laravel : `~/cga-connect/shared/storage/logs/`.

## 3. Retour arrière

```bash
cd ~/cga-connect && ls -1 releases/
ln -sfn ~/cga-connect/releases/<version précédente> current.next && mv -Tf current.next current
php current/backend/artisan queue:restart
```

Le SPA publié reste celui de la dernière installation : pour le revenir aussi, réinstaller le paquet de la version précédente.

## 4. Désinstaller

Retirer le bloc `cga-connect` du crontab (`crontab -e`), vider le dossier public du domaine, puis supprimer `~/cga-connect` et la base dans cPanel.
