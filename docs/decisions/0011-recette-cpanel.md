# 0011 — Recette sur l'hébergement cPanel de DC-KNOWING

- **Statut** : acceptée par DC-KNOWING pour la recette — la production reste sur un serveur dédié
- **Date** : 03/11/2026
- **Références** : 02-TRD §2, §3, §12 ; `infra/README.md` ; 06-IMPLEMENTATION-PLAN, étapes 0.2, 0.5 et 9.1 à 9.4

## Contexte

`infra/README.md` prévoit un serveur Ubuntu 24.04 (Nginx, Supervisor, LibreOffice, PostgreSQL 16) et écarte le mutualisé. DC-KNOWING souhaite une recette tout de suite, sur son hébergement cPanel LWS (`cga-connect-api.dc-knowing.com`), qui offre PHP 8.3 et ses extensions, Composer, PostgreSQL 13.23, le Terminal et un cron à la minute, mais ni Node, ni LibreOffice, ni Supervisor, ni SSH depuis l'extérieur.

## Décision

1. **Recette seulement.** Le dossier `infra/cpanel/` installe une recette sur cPanel ; la production pilote suit `infra/README.md` sur un serveur dédié, après les étapes 9.1 à 9.4.
2. **Même principe que `deploy.sh`** : une version par dossier, `.env` et stockage partagés, bascule du lien `current` une fois tout prêt, migrations additives. Le paquet est construit sur le poste (`build-package.sh`, SPA compris) puis déposé par le Gestionnaire de fichiers ; `install.sh` s'exécute dans le Terminal de cPanel.
3. **Tâches de fond** : un cron chaque minute lance le planificateur et un worker `queue:work --stop-when-empty` sous verrou `flock`, à la place de Supervisor.
4. **Aucun PDF** en recette cPanel : LibreOffice est absent. Les documents ne se génèrent pas et le contrôle `libreoffice` du point de santé est en échec, ce qui est attendu. La recette des documents se fera sur le serveur dédié.
5. **Sécurité** : le `.htaccess` reprend les règles et en-têtes de `infra/nginx` (CSP, HSTS, fichiers cachés interdits) ; le mot de passe de la base n'est saisi que sur le serveur ; le mot de passe des comptes de démonstration est tiré au hasard à l'installation (la valeur par défaut du code est publique) ; aucun envoi réel (`MAIL_MAILER=log`, identifiants de messagerie non saisis).
6. **Aucun changement de code** : la bascule ne passe que par la configuration (02-TRD §2).

## Conséquences

- Les parcours sans PDF (adhérents, obligations, clôture, honoraires, envois groupés en journal, paramètres) se recettent sur cPanel ; attestations, reçus, courriers, factures et rapport trimestriel attendent le serveur dédié.
- PostgreSQL 13 au lieu de 16 : aucune fonction propre aux versions 14 et plus n'est utilisée par le code à ce jour ; la CI reste sur PostgreSQL 16.
