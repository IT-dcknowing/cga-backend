# 0014 — Annonces : cibles, e-mail en option, fil reçu, lectures

- **Statut** : proposée — interprétations à confirmer par le CNCGA et DC-KNOWING (points ouverts ci-dessous)
- **Date** : 04/11/2026
- **Étapes** : 06-IMPLEMENTATION-PLAN, Lot 7, étape 7.3 (backend)
- **Références** : 01-PRD M12 (F-COM-01, 02), M13, §3.3, RG-SEC-01 ; 03-APPFLOW §3 (`/cncga/communications`), §4 (onglet Annonces de `/cga/communications`), §5 (« Message de mon CGA ») ; 05-BACKEND-SCHEMA §14 ; décision 0008 (filtre des envois groupés)

## Contexte

Le PRD fixe les cibles des annonces (CNCGA : tous les CGA, certains CGA, tous les adhérents du réseau ; CGA : ses adhérents, filtrés par régime ou secteur) et les canaux (plateforme toujours, e-mail en option). Il ne dit pas :
- qui, dans un CGA, reçoit une annonce du CNCGA ;
- si le portefeuille Cabinet est concerné ;
- si une annonce passe par un brouillon.

## Décisions

1. **Publication immédiate** : « + Nouvelle annonce » → **Publier** (03-APPFLOW). Il n'y a pas de brouillon. Le nombre de destinataires est compté à la publication (« Annonce publiée à n destinataires »).
2. **Droits** :
   - Le CNCGA publie avec `announcements.network.publish` (Présidente, gestionnaire, responsable des formations).
   - Un CGA publie avec le nouveau droit **`announcements.cga.publish`** (gérant et expert-comptable, F-COM-02), ajustable en base.
   - Chaque auteur relit ses annonces, avec le nombre de destinataires et de lectures.
3. **Destinataires**, comptés et notifiés à la publication :

   | Annonce | Reçue par |
   |---|---|
   | Du CNCGA « à tous les CGA » ou « à certains CGA » | Tous les agents actifs des CGA actifs visés |
   | Du CNCGA « à tous les adhérents du réseau » | Accès en ligne actifs des dossiers ouverts du **portefeuille CGA** |
   | D'un CGA | Accès en ligne actifs de ses dossiers retenus par le filtre des envois groupés (décision 0008) : portefeuille (CGA par défaut ; Cabinet ou tous avec `members.cabinet.view`), régimes, secteurs |

   Un adhérent sans accès en ligne ne reçoit pas d'annonce : l'annonce est un message de la plateforme.
4. **Fil reçu** (`GET /api/v1/me/announcements`, « Message de mon CGA ») :
   - Il reprend ces règles au moment de la lecture, sans copie par destinataire.
   - Il contient, pour un agent, les annonces du CNCGA à son CGA ; pour un adhérent, celles de son CGA qui le retiennent et celles du CNCGA au réseau.
   - Chaque annonce indique si elle a été lue ; le fil donne le nombre de non lues et se filtre sur les non lues.
   - Un accès en consultation seule (adhérent radié) ne le reçoit plus.
5. **Canaux** : la plateforme toujours, l'e-mail seulement si l'auteur l'a coché (`send_email`), dans la limite des préférences et de l'adresse du destinataire. Jamais de SMS ni de WhatsApp (M13).
6. **Lectures** : la première lecture d'un utilisateur est retenue (`announcement_reads`), les suivantes ne changent rien.
7. **Schéma** : la colonne `organization_id` de `announcement_targets` s'appelle **`target_organization_id`**, pour ne pas la confondre avec l'organisation auteur, qui seule passe par le cloisonnement. Ajouts : `recipients_count` et les horodatages de l'annonce.
8. **Pièce jointe** : un fichier des types `files.allowed_types` (contrôlés sur le contenu), rangé par `StorageService` dans l'organisation auteur et remis aux destinataires par lien temporaire signé.

## Preuve

`tests/Feature/Announcements/AnnouncementsTest.php` (F-COM-01, F-COM-02, RG-SEC-01), test de cloisonnement, collection Postman, dossier « 46. Étape 7.3 — Annonces ».

## Points ouverts

- Agents destinataires d'une annonce du CNCGA à un CGA : tous les agents actifs, ou le gérant seul ? À confirmer par le CNCGA.
- Clients du portefeuille Cabinet : exclus des annonces du réseau, visés par un CGA seulement s'il le choisit. À confirmer par DC-KNOWING.
- Retrait ou modification d'une annonce publiée : non prévus par le PRD, non réalisés.
