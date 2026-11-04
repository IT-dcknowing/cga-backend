# 0013 — Formations : ciblage, places, inscriptions, paiement, attestations

- **Statut** : proposée — interprétations à confirmer par DC-KNOWING et le CNCGA (points ouverts ci-dessous)
- **Date** : 04/11/2026
- **Étapes** : 06-IMPLEMENTATION-PLAN, Lot 7, étape 7.2 (backend)
- **Références** : 01-PRD M11 (F-FOR-01 à 04, RG-FOR-01 à 06), M13, §3.1, §3.3, RG-SEC-01 ; 03-APPFLOW §3 (`/cncga/formations`), §4 (`/cga/formations`), §5 (`/adherent/formations`) ; 05-BACKEND-SCHEMA §13 ; 02-TRD §11 (`trainings:waitlist`)

## Contexte

Le PRD décrit les publics, la liste d'attente, la désinscription à J-2, la validation, le paiement et l'après-session. Il ne précise pas :
- quels rôles forment chaque public « agents » ;
- si le portefeuille Cabinet est ciblé ;
- ce que voit le CNCGA des adhérents inscrits à ses formations ;
- quand une session peut être clôturée.

Les choix ci-dessous sont des paramètres ou une table de configuration.

## Décisions

1. **Organisateur et cloisonnement** :
   - Une formation appartient à son organisateur : le CNCGA (formation du réseau) ou un CGA.
   - Une inscription appartient au CGA de la personne inscrite. C'est l'`attendee_organization_id` du schéma, porté par `organization_id` pour le cloisonnement.
   - Elle est rattachée à l'espace où la personne est ciblée (`membership_id` : agent, ou accès adhérent).
   - Les CGA et les personnes lisent les formations publiées du réseau, et l'organisateur lit les inscriptions de ses formations, par des contournements nommés.
   - Un brouillon n'est vu que de son organisateur.
2. **Publics** :

   | Public | Personnes ciblées |
   |---|---|
   | « Responsables CGA » | Gérant et expert-comptable |
   | « Comptables CGA » | Expert-comptable et comptable |
   | « Tous les agents CGA » | Tous les rôles d'agent |
   | Publics « adhérents » | Accès en ligne actifs des dossiers ouverts du **portefeuille CGA** (régime RME ou TEE ; adhésion depuis moins de `trainings.new_member_months` mois, 6) |

   Les rôles des publics « agents » sont réglés dans `config/cga.php` (`trainings.audience_roles`). Seuls les CGA actifs sont ciblés. Une formation d'un CGA ne cible que ses propres agents et adhérents (RG-FOR-01).
3. **Publication** (RG-FOR-01, 02) : directe, sans validation croisée, avant le début de la session. Sont notifiés le public ciblé et, pour une formation du CNCGA, les agents qui gèrent les formations (`trainings.cga.manage`) des CGA dont des personnes sont ciblées.
4. **Inscription** :
   - **Qui inscrit** : la personne ciblée elle-même (un adhérent avec `member.trainings.register`, ou un agent actif d'un CGA), son CGA (`trainings.cga.manage`), ou l'organisateur.
   - **Qui peut être inscrit** : une personne ciblée seulement (409 sinon), une fois par formation hors annulation, avant le début de la session.
   - **Statut obtenu** :
     - avec validation, « en attente de validation » (RG-FOR-05) ;
     - sinon « inscrit » tant qu'il reste des places ;
     - puis « en liste d'attente », dans l'ordre d'arrivée (RG-FOR-03).

   Une validation prend une place libre ou rejoint la liste d'attente. Une inscription refusée n'est pas renouvelable.
5. **Promotion de la liste d'attente** (RG-FOR-03), notifiée :
   - immédiate à chaque désistement ou hausse du nombre de places ;
   - puis `trainings:waitlist`, toutes les 15 minutes, par sécurité.

   Le nombre de places ne descend jamais sous le nombre d'inscrits (409).
6. **Désinscription** (RG-FOR-04) :
   - jusqu'à J-`trainings.cancellation_days` (2) avant le début, par la personne ou par son CGA ;
   - la personne est notifiée quand son CGA la désinscrit.
7. **Paiement** (RG-FOR-06) :
   - une formation payante met chaque inscription « à payer » ;
   - l'organisateur enregistre le paiement encaissé hors plateforme (date, mode, référence) d'une personne inscrite ; l'inscription passe « payée » ;
   - un **reçu de formation** est produit, numéroté `numbering.training_receipt.pattern` (`FOR-{ABR}-{YYYY}-{N:5}`, séquence de l'organisateur), sur un modèle provisoire dérivé du reçu de cotisation (nouveau type de document `training_receipt`) ;
   - un changement de prix met à jour les inscriptions non réglées.
8. **Après la session** (F-FOR-04) :
   - à partir du début, l'organisateur émarge chaque inscrit (présent ou absent) ;
   - **« Clôturer la session »** exige un émargement complet (409 sinon) et termine la formation ;
   - une **attestation de participation** est produite pour chaque présent (modèle provisoire `training_certificate`), et la personne en est notifiée ;
   - la personne télécharge son reçu et son attestation depuis « Mes inscriptions ». Ils ne vont pas dans « Mes documents », car ce sont des documents de l'organisateur.
9. **Évaluation à chaud** : une note de 1 à 5 et un commentaire, par un présent, après la clôture, modifiable. L'organisateur voit les résultats agrégés (moyenne, répartition, commentaires sans nom).
10. **RG-SEC-01 et formations du CNCGA** :
    - Le CNCGA n'inscrit que des agents des CGA.
    - Il voit les adhérents inscrits par leur nom, leur CGA et « Adhérent », **jamais leur entreprise**. Leur attestation porte « Adhérent de {CGA} » au lieu de la raison sociale.
    - Le CGA de la personne voit l'entreprise.
11. **Annulation** : l'organisateur annule un brouillon ou une formation publiée, avec un motif, et les personnes inscrites ou en attente sont notifiées. Une session clôturée ne s'annule plus. Une formation commencée, terminée ou annulée ne se modifie plus.
12. **Rappel J-1** (PRD M13) : `reminders:trainings`, chaque jour à 07:40, envoie un seul rappel à chaque inscrit d'une session qui commence d'ici la fin du jour J+`trainings.reminder_days` (1). Cette tâche n'est pas listée au 02-TRD §11.

## Preuve

`tests/Feature/Trainings/TrainingsTest.php` (noms contenant RG-FOR-01 à RG-FOR-06, RG-SEC-01, F-FOR-01 à 04), test de cloisonnement, modèles de documents (`DocumentTemplatesTest`), collection Postman, dossier « 45. Étape 7.2 — Formations ».

## Points ouverts

- Rôles de « Responsables CGA » et « Comptables CGA » : à confirmer par le CNCGA (table `trainings.audience_roles`).
- Clients du portefeuille Cabinet : non ciblés par les publics « adhérents ». À confirmer par DC-KNOWING (formations du centre pour ses clients Cabinet ?).
- Adhérents inscrits à une formation du CNCGA : le CNCGA voit leur nom (nécessaire à l'émargement et à l'attestation), sans leur entreprise. À confirmer par le CNCGA au regard de RG-SEC-01.
- « Supports » de formation (F-FOR-01) : absents du schéma et du plan de l'étape 7.2, non réalisés. À confirmer avant l'écran de l'étape 7.5.
- Modèles du reçu et de l'attestation : provisoires, à remplacer par les modèles définitifs (écran des modèles).
