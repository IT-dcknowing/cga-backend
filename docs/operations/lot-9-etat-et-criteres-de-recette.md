# Lot 9 — durcissement et généralisation : état de livraison

Branche : `feat/lot-9-1-9-4-backups-retention`
Base de référence : `front/reprise-ecrans-acces-registre`
Périmètre : backend Laravel CGA Connect.

Ce document distingue les éléments déjà présents dans le backend, les ajouts de cette branche et les validations qui nécessitent un environnement d'exploitation ou le frontend. Une fonctionnalité présente dans le code n'est pas considérée comme opérationnelle tant que sa configuration et ses tests de déploiement ne sont pas validés.

## Synthèse des sous-lots

| Sous-lot | État | Constat / livraison | Conditions de clôture |
|---|---|---|---|
| 9.1 Sauvegardes et restauration | En cours | Procédure de sauvegarde chiffrée et restauration isolée documentée. | Automatisation adaptée à l'hébergeur, stockage distant, clés hors site, RPO/RTO approuvés et exercice de restauration réussi. |
| 9.2 Supervision | Partiel | Sentry configuré sans PII par défaut ; endpoint `/api/health` et contrôles DB, queue, stockage et LibreOffice ; journaux de secours JSON. | Vérifier DSN et alertes Sentry en recette/prod, ajouter mesure du retard de file et supervision externe, décider du format JSON centralisé des logs applicatifs. |
| 9.3 Sécurité | Partiel à auditer | Authentification, permissions, isolation multi-tenant et tests de sécurité existent. | Audit OWASP, vérification CSP/HSTS/cookies/TLS/headers et throttling sur infrastructure, revue des dépendances et test d'intrusion indépendant. |
| 9.4 Conservation et purge | Partiel | Politique de conservation documentée ; commande `logs:prune` en simulation par défaut, suppression seulement avec `--execute`. | Tests PostgreSQL réels, validation conformité des durées, gel légal, anonymisation utilisateurs, purge CGA et nettoyage des fichiers après inventaire complet. |
| 9.5 Accès support plateforme | Partiel | Modèle et table `platform_access_logs` prévus pour journaliser l'accès d'un administrateur plateforme à un CGA. | Vérifier le cycle complet d'ouverture/fermeture, motif obligatoire, journal non altérable, notification/consultation par le CGA et permissions plateforme. |
| 9.6 Performance 50 000 adhérents | Non validé | La suite de tests et la commande `composer test:performance` existent. | Générer un jeu réaliste de 50 000 adhérents, exécuter les scénarios de charge sur environnement représentatif et joindre les mesures. |
| 9.7 WCAG AA et responsive | Hors périmètre backend | Les contrôles d'accessibilité portent principalement sur l'interface frontend. | Audit clavier, lecteur d'écran, contraste, focus, formulaires et responsive sur les écrans frontend réels. |
| 9.8 Guides et démonstration | Partiel | Des seeders et outils de génération de données de démonstration existent. | Guide utilisateur par rôle, guide administrateur/exploitation, scénario de démonstration reproductible et données fictives vérifiées. |

## 9.1 — Sauvegardes

Le stockage privé est configuré par `FILES_ROOT` et les métadonnées de fichiers portent une empreinte SHA-256. Le runbook `lot-9-1-sauvegarde-restauration.md` décrit la sauvegarde PostgreSQL + stockage privé, le chiffrement age, les contrôles d'intégrité et la restauration isolée.

**Pas encore livré :** un orchestrateur de sauvegarde automatisé. Il dépend du fournisseur d'hébergement, du stockage distant, de la gestion des clés et des objectifs RPO/RTO. Ne pas mettre en production une tâche générique qui pourrait laisser des archives en clair ou créer des sauvegardes incohérentes.

## 9.2 — Supervision

Les contrôles santé existants ne remplacent pas une supervision externe. En particulier, la taille de la file vérifie que le backend répond, mais ne mesure pas à elle seule l'âge du plus ancien job. Définir des seuils d'alerte par file et un contrôle adapté au driver réellement utilisé (database, Redis ou SQS).

Sentry doit être configuré avec un DSN et une version applicative ; garder les données personnelles désactivées et les bindings SQL masqués.

## 9.3 — Sécurité

Avant clôture, exécuter une checklist OWASP ASVS adaptée à l'application : contrôle d'accès par organisation et rôle, gestion des sessions, MFA/OTP selon les exigences, validation d'entrée, téléversement et téléchargement, CSRF/CORS, limitation de débit, secrets, erreurs, journaux, dépendances et exposition réseau.

CSP, HSTS, TLS, règles Nginx/WAF et en-têtes de sécurité doivent être contrôlés sur l'environnement déployé : ils ne peuvent pas être attestés par la seule lecture du backend.

## 9.4 — Conservation

Les paramètres existants sont 24 mois pour les journaux d'activité, 60 mois pour les journaux d'audit et 6 mois pour l'éligibilité générale après résiliation. Ces délais ne doivent pas effacer les documents ou preuves soumis à une conservation plus longue.

La commande `php artisan logs:prune` est une simulation. La suppression exige `php artisan logs:prune --execute --confirm-no-legal-hold`, après vérification humaine et documentée des gels juridiques. Une exécution avec `--execute` sans cette confirmation est refusée. La commande n'est volontairement pas planifiée automatiquement : le responsable conformité doit valider les durées et les éventuelles obligations de gel avant activation.

La purge générale d'une organisation résiliée, l'anonymisation des comptes et le nettoyage des fichiers ne sont pas autorisés par cette commande et restent à implémenter après cartographie des relations et règles métier.

## 9.5 — Support plateforme

La table `platform_access_logs` est un début de traçabilité, pas une preuve suffisante de clôture. Le test de réception doit couvrir un accès autorisé et refusé, le motif, l'identité de l'agent, le CGA ciblé, l'horodatage de début/fin, l'impossibilité de modifier les traces et la consultation côté CGA.

## 9.6 — Performance

Les mesures doivent être réalisées avec PostgreSQL et les mêmes index, paramètres de workers, stockage et files que l'environnement cible. Jeu de données minimal : 50 000 adhérents répartis sur plusieurs CGA, avec documents, obligations, paiements et historiques représentatifs. Mesurer p50/p95/p99, débit, erreurs, temps SQL, mémoire, CPU et retard de queue. Ne pas publier de résultat sans rapport d'exécution horodaté.

## 9.7 — Accessibilité et responsive

À vérifier dans le frontend : WCAG 2.2 AA (ou version contractuellement retenue), navigation clavier complète, focus visible, structure des titres, labels, erreurs annoncées, contrastes, zoom 200 %, tableaux, modales et tailles d'écran mobile/tablette/desktop. Les tests automatisés doivent être complétés par des vérifications manuelles.

## 9.8 — Guides et démonstration

Préparer les guides CGA, adhérent, CNCGA et administrateur plateforme. Le jeu de démonstration doit être fictif, réinitialisable et sans donnée réelle. Documenter les comptes de démonstration hors dépôt ; ne jamais committer de mots de passe ou secrets.

## Procédure de validation finale

1. Exécuter lint, PHPStan et la suite Pest complète sur la branche.
2. Exécuter les tests PostgreSQL des partitions et de la commande `logs:prune`.
3. Vérifier l'absence de suppression en mode simulation.
4. Exécuter les exercices de sauvegarde/restauration sur un environnement isolé.
5. Faire signer la matrice de conservation et les règles de gel par le responsable conformité.
6. Réaliser audit OWASP, test d'intrusion, charge 50 000 adhérents et audit WCAG AA.
7. Joindre les rapports, corriger les écarts bloquants et seulement ensuite sortir la PR du mode brouillon.

**État global : le lot 9 n'est pas encore clôturable.** Le backend contient déjà plusieurs fondations et cette branche ajoute la politique opératoire et une purge de journaux explicitement contrôlée. Les opérations de production, la conformité, la charge, l'accessibilité et certains traitements de conservation restent des critères de réception obligatoires.
