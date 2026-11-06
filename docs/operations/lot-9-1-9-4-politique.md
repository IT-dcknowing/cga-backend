# Lots 9.1 et 9.4 — Politique de sauvegarde et de conservation

> Statut : conception validée, mise en œuvre progressive.
> Branche de travail : `feat/lot-9-1-9-4-backups-retention`.

## Décisions métier retenues

- Le délai général de conservation après résiliation d'un CGA reste de 6 mois, conformément à `subscription.data_retention_months` (valeur par défaut actuelle).
- Ce délai n'autorise pas la suppression automatique des éléments soumis à une durée légale, réglementaire, contractuelle ou probatoire plus longue.
- Les éléments soumis à une obligation de conservation sont isolés du processus de purge générale et conservés jusqu'à leur échéance propre.
- Les durées particulières doivent être configurables et validées par le responsable juridique/conformité avant activation en production.
- Aucune purge irréversible ne doit être déclenchée si la classification d'une donnée est inconnue ou si une conservation légale est en cours.

## 9.4 — Règles de conservation et d'effacement

### Principes obligatoires

1. **Éligibilité distincte de suppression** : `data_deletion_due_at` indique une date d'éligibilité, pas une instruction de supprimer aveuglément toutes les données.
2. **Suspension préalable** : une organisation résiliée ne doit plus permettre d'opérations métier ordinaires avant tout effacement.
3. **Périmètre isolé** : chaque traitement doit être borné par l'identifiant de l'organisation et fonctionner en lots limités.
4. **Préservation légale** : les pièces comptables/fiscales, contrats, documents officiels, preuves de consentement et journaux probatoires doivent être évalués selon leur catégorie et leur échéance propre.
5. **Anonymisation avant effacement lorsque pertinent** : les données personnelles qui ne doivent plus être identifiantes sont anonymisées de façon irréversible, sans altérer les agrégats nécessaires.
6. **Fichiers** : aucun objet du stockage ne doit être supprimé avant vérification qu'il n'est référencé par aucune ligne métier, aucun document officiel ni aucune relation de conservation.
7. **Audit** : chaque exécution conserve un bilan non sensible (date, traitement, organisation concernée par identifiant technique, compteurs, résultat, identifiant d'exécution).
8. **Idempotence** : relancer un traitement ne doit pas supprimer davantage ni altérer un résultat déjà traité.
9. **Mode simulation** : toute commande de purge doit proposer un mode dry-run par défaut ou explicitement disponible, et produire le nombre d'éléments candidats sans les modifier.
10. **Validation humaine** : l'effacement final d'un CGA doit être précédé d'un rapport de prévisualisation et d'une validation autorisée.

### Matrice provisoire

| Catégorie | Règle |
|---|---|
| Journaux d'activité | Paramètre existant : 24 mois. Purge par partitions mensuelles uniquement après validation des bornes de partition. |
| Journaux d'audit | Paramètre existant : 60 mois. Purge par partitions mensuelles uniquement après validation des obligations probatoires. |
| Utilisateurs inactifs | Préavis, puis désactivation contrôlée ; ne pas confondre expiration de session et inactivité du compte. |
| Données d'un CGA résilié | Éligibles à partir de `data_deletion_due_at`, sous réserve des catégories à conservation prolongée et des contentieux/conservations légales. |
| Documents officiels et comptables | Conservation indépendante du délai général de 6 mois ; échéance à déterminer par catégorie et validation conformité. |
| Fichiers non référencés | Nettoyage uniquement après contrôle exhaustif des références et délai de quarantaine. |
| Comptes personnels | Anonymisation sélective ; conserver les traces strictement nécessaires avec pseudonymisation des identifiants lorsque possible. |

### Pré-requis avant activation des purges irréversibles

- Valider une matrice de conservation par catégorie documentaire avec le responsable juridique/comptabilité.
- Définir les cas de gel de conservation (litige, contrôle, enquête, demande d'autorité).
- Identifier les références documentaires et les contraintes de clés étrangères pour chaque domaine.
- Vérifier la sauvegarde et la restauration avant d'activer une purge destructive.
- Tester sur une copie anonymisée de production et produire un rapport de rapprochement avant/après.

## 9.1 — Politique de sauvegarde et restauration

### Périmètre à sauvegarder ensemble

- Base PostgreSQL (schéma, données, séquences, contraintes et extensions nécessaires).
- Stockage privé configuré par `FILES_ROOT` : documents, pièces, exports et fichiers générés encore requis.
- Configuration d'exploitation non secrète et version applicative exacte.
- Métadonnées de sauvegarde, manifeste, empreintes SHA-256 et horodatages.

### Sécurité

- Chiffrer les archives avant leur transfert vers un stockage de sauvegarde séparé.
- Ne jamais stocker la clé de chiffrement dans la même archive ni dans le même emplacement que celle-ci.
- Utiliser un compte de service à privilèges minimaux et un bucket privé avec versioning/verrouillage si le fournisseur le permet.
- Ne jamais écrire de secrets ou de données personnelles dans les journaux de sauvegarde.
- Vérifier l'intégrité des archives avant toute restauration.

### Cycle de sauvegarde à paramétrer

- Sauvegarde quotidienne avec possibilité de restauration à un instant donné selon la capacité PostgreSQL.
- Points hebdomadaires et mensuels conservés selon une politique approuvée.
- Copie hors site indépendante de l'environnement applicatif.
- Alertes en cas d'échec, d'absence de sauvegarde attendue ou d'échec de vérification.

Les durées de rétention des sauvegardes, le RPO et le RTO doivent être fixés avec l'exploitant avant activation. Aucune valeur arbitraire n'est imposée dans le code.

### Test de restauration

Un test de restauration doit être exécuté périodiquement sur un environnement isolé :
1. récupérer une sauvegarde et sa clé depuis les emplacements autorisés ;
2. vérifier les empreintes et déchiffrer dans un espace protégé ;
3. restaurer PostgreSQL et le stockage privé ;
4. vérifier les relations `files.path` / disque, les empreintes `files.sha256`, les volumes et les tables critiques ;
5. exécuter les contrôles applicatifs et un scénario de connexion multi-tenant ;
6. consigner le résultat, la durée et les écarts, puis détruire l'environnement temporaire de manière sûre.

## Garde-fous de livraison

- Aucun traitement destructif ne doit être planifié tant que les tests de restauration ne sont pas concluants.
- Les tâches planifiées doivent utiliser `withoutOverlapping()` et `onOneServer()`, comme les autres tâches du projet.
- Les traitements doivent être testés sur PostgreSQL ; SQLite ne suffit pas pour valider les partitions natives.
- Les commandes doivent être testables avec `--dry-run`, `--organization=` si applicable, et des limites de lots.
- La validation finale exige des tests d'idempotence, de cloisonnement multi-CGA, de conservation légale et de reprise après erreur.
