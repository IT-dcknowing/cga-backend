# 0018 — Accès support journalisé, test de restauration et supervision (Lot 9)

- **Statut** : acceptée — décisions de gestion confirmées par le porteur du projet le 07/11/2026
- **Date** : 07/11/2026
- **Étape** : 06-IMPLEMENTATION-PLAN, Lot 9, étapes 9.1, 9.2 et 9.5
- **Références** :
  - 01-PRD : RG-SEC-04, RG-SEC-05, F-ADM-03 ;
  - 02-TRD : §6.3 (contournements autorisés), §6.5 (journaux), §6.7 (A09), §12.3 (exploitation) ;
  - 03-APPFLOW : §6 (onglets « Journaux », « Accès support », « Technique » de `/plateforme`) ;
  - 05-BACKEND-SCHEMA : `platform_access_logs`.

## Contexte

Le PRD (RG-SEC-04) réserve l'accès aux données d'un CGA à l'équipe technique (`platform_admin`), pour la maintenance, avec un journal que le CGA peut obtenir. Le parcours (03-APPFLOW §6) prévoit : « Choisir un CGA + motif → session journalisée, bandeau rouge « Accès support à {CGA} — journalisé » pendant toute la session ».

Les documents ne disent pas :
- ce que l'équipe technique peut faire dans les données du CGA pendant la session ;
- comment la session se termine.

## Décisions

### 1. Accès support en lecture seule (confirmé le 07/11/2026)

- Pendant une session d'accès support, `platform_admin` **consulte** les écrans et les données du CGA choisi, avec les droits de lecture du gérant.
- **Aucune écriture** : toute requête qui modifie (méthode autre que GET / HEAD) répond 409 `support_read_only`. Les corrections restent faites par le CGA lui-même.
- Chaque requête de la session est journalisée dans `activity_logs` (avec la session d'accès) ; la session elle-même est une ligne de `platform_access_logs` (motif, début, fin).

### 2. Fin de session (confirmé le 07/11/2026)

La session se termine par le premier de ces événements :
- le bouton « Terminer l'accès » ;
- la durée maximale, paramètre de la plateforme `support.session_max_minutes` (défaut 60 min) ;
- la déconnexion.

### 3. Données d'un CGA résilié (RG-ORG-07, confirmé le 07/11/2026)

Six mois après la résiliation (`data_deletion_due_at`), on supprime ce qui n'est **pas** couvert par une durée légale du PRD §7 :
- comptes d'accès du centre (agents et adhérents : rattachements, puis utilisateurs sans autre rattachement, anonymisés) ;
- préoccupations et échanges, historique des messages, notifications ;
- pièces justificatives des dossiers ;
- paramètres, droits ajustés et connexions propres au centre.

Restent jusqu'à leur propre échéance (10 ans), puis suivent la purge normale :
- dossiers des adhérents (anonymisés 10 ans après radiation ou clôture) ;
- contrats, attestations, reçus, factures certifiées FNE ;
- rapports trimestriels, obligations et paiements suivis.

Le CNCGA conserve les agrégats anonymisés et l'historique des cotisations.

### 4. Archivage à 10 ans (PRD §7, confirmé le 07/11/2026)

« Archivage » des rapports trimestriels, des obligations et des paiements suivis : les lignes restent en base, marquées archivées (`archived_at`), **exclues des listes et écrans courants**, sans aucune suppression.

### 5. Test de restauration (étape 9.1)

- Chaque sauvegarde écrit un **manifeste** (`*.manifest.json`, sans donnée nominative) : nombre de lignes de chaque table, lu dans le même instantané PostgreSQL que `pg_dump` (`pg_export_snapshot` / `--snapshot`), nombre et taille des fichiers archivés.
- La commande `backup:restore-test {base}` restaure, sur un serveur isolé, la base et le jeu de fichiers, les compare au manifeste et produit un rapport JSON pour le registre d'exploitation (docs/backup-restore.md). Elle refuse la production, la base de l'application et une base non vide.
