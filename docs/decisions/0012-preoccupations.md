# 0012 — Préoccupations : fil, délais, transfert au CNCGA, indicateurs

- **Statut** : proposée — interprétations à confirmer par DC-KNOWING et le CNCGA (points ouverts ci-dessous)
- **Date** : 04/11/2026
- **Étapes** : 06-IMPLEMENTATION-PLAN, Lot 7, étape 7.1 (backend)
- **Références** : 01-PRD M10 (F-PRE-01 à 04, RG-PRE-01 à 03), F-DOC-06, M13, §3.3, RG-SEC-01, RG-PTF-02, RG-PTF-07 ; 03-APPFLOW §3 (`/cncga/preoccupations`), §4 (`/cga/preoccupations`), §5 (`/adherent/messages`, « Demander un document », « Déposer des pièces ») ; 05-BACKEND-SCHEMA §12, §16 ; 02-TRD §11 (`reminders:concerns`)

## Contexte

Le PRD fixe les champs, les statuts, les délais visés et le transfert au CNCGA, mais laisse ouverts quelques détails :
- ce qui compte comme « réponse » pour l'alerte à 15 jours ;
- ce que voit le CNCGA de la copie d'un échange d'adhérent, alors que RG-SEC-01 lui interdit toute donnée nominative ;
- qui est averti de l'activité du demandeur.

Les choix ci-dessous sont des paramètres ou sont isolés dans un seul service.

## Décisions

1. **Deux niveaux, une table** :
   - `member_to_cga` : de l'adhérent à son CGA. Le dossier est exigé.
   - `cga_to_cncga` : du gérant ou de l'expert-comptable au CNCGA. Pas de dossier.

   Toutes les demandes, leurs messages et leurs pièces jointes portent le CGA concerné (`organization_id`) et passent par le cloisonnement. Le CNCGA lit les demandes des CGA par un contournement nommé et journalisé ; il ne lit jamais une demande d'adhérent : 404, comme une demande inexistante.
2. **Catégories** : les dix du PRD, plus **« Pièces de clôture »** (`closing_pieces`). C'est la demande que crée « Déposer des pièces » de `/adherent/cloture` (03-APPFLOW §5). Le schéma ne la prévoyait pas ; elle sert à l'écran de l'étape 4.11.
3. **Droits** (en base, ajustables) :

   | Qui | Droit | Ce qu'il permet |
   |---|---|---|
   | Agents du CGA | `concerns.reply` | Lire et traiter les demandes des adhérents. Un dossier du portefeuille Cabinet exige en plus `members.cabinet.view` (RG-PTF-02). |
   | Gérant et expert-comptable | `concerns.network.write` | Écrire au CNCGA et lui transférer une demande. |
   | Gérant seul (nouveau droit) | `concerns.indicators.view` | Consulter le tableau des délais du centre. |
   | CNCGA | `network_concerns.view` | Lire les demandes des CGA ; c'est le droit du bureau. |
   | CNCGA | `network_concerns.handle` | Traiter les demandes des CGA. |
   | Adhérent | `member.concerns.write` | Écrire à son CGA. Un accès en consultation seule (adhérent radié) n'y a plus droit. |

4. **Statuts** :
   - Celui qui traite la demande la fait passer d'« Ouverte » à « En examen », « Traitée » ou « Rejetée » (motif obligatoire), et d'« En examen » à « Traitée » ou « Rejetée ». Aucun autre passage n'est permis (409).
   - Le demandeur rouvre une demande traitée ou rejetée : elle revient à « Ouverte ». Le motif est retiré de la demande mais reste dans le fil.
   - Une demande traitée ou rejetée ne reçoit plus de message avant sa réouverture (409).
   - Chaque changement s'inscrit au fil comme message système.
5. **Notes internes** : écrites seulement par celui qui traite la demande. Elles ne sont jamais lues pour le demandeur (ni l'adhérent, ni le CGA pour une demande au CNCGA) et ne déclenchent aucune notification.
6. **Délais visés (RG-PRE-01)** :
   - `concerns.target_days` (réseau) : 7 jours pour une demande d'adhérent, 15 jours pour une demande au CNCGA. La valeur est figée sur la demande à sa création.
   - La réponse expose le délai écoulé et un indicateur « en retard sur le délai visé », sans rien bloquer.
7. **« Sans réponse » (RG-PRE-02)** :
   - La demande attend une réponse depuis le dernier message du demandeur, sa création ou sa réouverture (`awaiting_reply_since`).
   - Elle n'attend plus quand celui qui la traite répond publiquement, la clôt (traitée ou rejetée) ou la transmet au CNCGA. Une note interne ou un passage « En examen » ne valent pas réponse.
   - `reminders:concerns` (07:30) alerte, une fois par attente, les agents qui répondent aux adhérents, quand une demande d'adhérent attend depuis `concerns.stale_days` jours (15). Le nombre indiqué est celui des demandes que l'agent voit.
   - Rien ne remonte au CNCGA.
8. **Notifications** :

   | Événement | Destinataires | Type |
   |---|---|---|
   | Nouvelle demande | Ceux qui la traitent | `concern_new` |
   | Réponse ou changement de statut | Le demandeur : les accès en ligne du dossier, ou le gérant et l'expert-comptable pour une demande au CNCGA | `concern_updated` |
   | Nouveau message du demandeur ou réouverture | Ceux qui traitent la demande | `concern_followup` (absent de M13) |

9. **Transfert au CNCGA (F-PRE-03)** et RG-SEC-01 :
   - **Ce qui est créé** : une demande liée au CNCGA, qui reprend la catégorie et l'objet. Elle contient la **copie des seuls messages publics** de l'échange, puis le message d'accompagnement et ses pièces jointes.
   - **Ce qui n'est pas copié** : les pièces jointes de l'échange d'origine, les notes internes et les messages système.
   - **Auteurs** : chaque message copié n'indique que son côté (« Adhérent », « CGA »), jamais un nom.
   - **Dossier** : la demande liée ne porte aucun dossier. Le gérant joint lui-même, au message d'accompagnement, les pièces utiles au CNCGA.
   - **La demande d'origine** passe « En examen » et porte la mention « Transmise au CNCGA ». L'adhérent est notifié. Le CGA voit le statut de la demande liée.
   - **Limites** : une demande ne se transmet qu'une fois, et jamais une fois traitée ou rejetée (409).
10. **Indicateurs (F-PRE-04, RG-PRE-03)** :
    - **Chiffres** : nombre, répartition par statut et par catégorie, taux de traitement (traitées et rejetées), délais moyens de première réponse et de clôture (heures), part des premières réponses dans le délai visé.
    - **Période** : facultative, sur la date de dépôt.
    - **Gérant** : son centre, les deux portefeuilles, et les demandes sans réponse.
    - **CNCGA** : le réseau et chaque CGA, sur les seules demandes d'adhérents du **portefeuille CGA**. Le portefeuille Cabinet n'entre dans aucune statistique du CNCGA. Aucun objet, dossier ou texte.
11. **Demande de document (F-DOC-06)** : « Demander un document » crée une demande « Demande de document » dont l'objet nomme le document (attestation, états financiers, autre). Le message est exigé pour « autre ».
12. **Pièces jointes** : au plus `concerns.max_attachments` fichiers par message (5), des types `files.allowed_types`, contrôlés sur le contenu, chacun d'au plus `files.max_upload_mb`. Ils sont rangés par `StorageService` dans le CGA de la demande et téléchargés par lien signé.

## Preuve

`tests/Feature/Concerns/ConcernsTest.php` (noms contenant RG-PRE-01, RG-PRE-02, RG-PRE-03, RG-SEC-01, RG-PTF-02, F-PRE-01 à 04, F-DOC-06), test de cloisonnement (`tests/Feature/Security/TenantIsolationTest.php`), collection Postman, dossier « 44. Étape 7.1 — Préoccupations ».

## Points ouverts

- Copie de l'échange transmis au CNCGA : le PRD exige la copie (F-PRE-03), RG-SEC-01 interdit au CNCGA toute donnée nominative d'adhérent. Le texte libre écrit par l'adhérent peut contenir son nom : à confirmer par le CNCGA (copie intégrale, copie sans texte de l'adhérent, ou avertissement au gérant avant l'envoi).
- Notification `concern_followup` (absente de M13) : à confirmer par DC-KNOWING.
- Qui reçoit l'alerte à 15 jours : tous les agents qui répondent aux adhérents, ou le gérant seul ? À confirmer par DC-KNOWING.
- Conservation de 5 ans (PRD §7) : la purge relève de `retention:purge` (Lot 9).
