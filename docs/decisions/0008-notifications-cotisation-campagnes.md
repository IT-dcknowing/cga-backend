# 0008 — Notifications, rappels, cotisation CNCGA, messages groupés, factures de cotisation

- **Statut** : proposée — interprétations à confirmer par DC-KNOWING (rappels, cotisation) et le CNCGA (cotisation, facture)
- **Date** : 02/11/2026
- **Étapes** : 06-IMPLEMENTATION-PLAN, Lot 6, étapes 6.1 à 6.9 (backend et écrans)
- **Références** : 01-PRD M13 (RG-NOT-01 à 05, F-COM-03, RG-COM-01 et 02), M14 (F-COT-01 à 06, RG-COT-01 à 06), F-PAY-06, RG-FNE-01 à 05, CR-04 ; 02-TRD §10, §11 ; 05-BACKEND-SCHEMA (notifications, messaging_providers, message_logs, sms_quotas, network_dues, message_campaigns)

## Contexte

Le PRD décrit les canaux, les rappels et la cotisation sans fixer certaines règles de détail : la fréquence d'un rappel répété, ce qui évite un double envoi quand une tâche planifiée est relancée, le calcul exact du prorata, le contenu de la facture de cotisation. Les choix ci-dessous sont des paramètres (`settings`, `config/`) ou isolés dans un seul service, pour pouvoir être ajustés sans reprise.

## Décisions

1. **Moteur unique** : toute notification passe par les notifications Laravel (`PlatformNotification`), après validation de la transaction ; canaux par type dans `config/notifications.php`, textes dans `lang/fr/notifications.php`. Chaque envoi (réussi ou en échec) est tracé dans `message_logs` et `activity_logs`, sans le contenu des identifiants.
2. **Canaux** (`NotificationRouting`) : la plateforme et l'e-mail ne sont jamais limités ; SMS et WhatsApp d'un type non essentiel demandent le consentement du destinataire ; un canal sans compte d'envoi actif bascule sur la plateforme et l'e-mail ; un type contractuel sans e-mail part par SMS ; SMS et WhatsApp hors de la plage `messaging.send_window` partent à son ouverture.
3. **Comptes d'envoi** : saisis dans l'écran « Envois » de l'administrateur (identifiants chiffrés, jamais réaffichés hors des 4 derniers caractères), un compte actif par canal. En développement et en CI, pilotes « journal » : aucun envoi réel. L'e-mail passe par le mailer `platform`, qui prend le compte SMTP actif et, à défaut, le mailer de repli `MAIL_FALLBACK_MAILER`.
4. **Quota SMS** : `sms.monthly_quota` par centre et par mois (plus les packs achetés), décompté au départ ; les codes de connexion sont hors quota. Quota épuisé : le SMS est refusé et tracé, la notification part sur les autres canaux.
5. **Rappels planifiés** (`reminders:obligations`, `fees`, `documents`, `agreements`, `dues`) : alertes seulement (CR-04). Échéances à J-7 et J-1 (`obligations.reminder_days`) puis dépassement ; honoraires impayés le jour du passage en impayé puis tous les `fees.reminder_every_days` jours ; pièces attendues depuis `pieces.reminder_days` jours, puis à chaque période ; agrément à 6 et 3 mois (`agreements.alert_months`) ; dossier RSI/RNI au portefeuille CGA à J+60 et J+90 (`rsi.alert_days`).
6. **Idempotence** : chaque rappel est daté sur son objet (échéance, cotisation, rapport) ou mémorisé par une clé de cache par objet, jour et événement : relancer une tâche le même jour ne renvoie rien.
7. **Cotisation — appel** (`dues:open-year`, le 1er janvier) : une cotisation par CGA non résilié et par an, montant `dues.amount` (750 000 FCFA provisoire), date butoir `dues.deadline` (30 juin). **Prorata** (`dues.prorata` = `semester`) : un CGA dont la **date d'agrément** tombe au second semestre de l'année doit la moitié ; sinon le montant entier.
8. **Cotisation — paiements** : le gérant déclare un paiement avec sa preuve (à confirmer, le CNCGA est averti) ; le CNCGA confirme (reçu numéroté `CNC-{YYYY}-{N:5}` (PRD F-DOC-01), modèle `dues_receipt` provisoire) ou rejette avec motif ; il peut aussi saisir un paiement reçu, confirmé d'emblée. Un paiement ne dépasse jamais le reste dû.
9. **Cotisation — statut et relances** : À jour, Partielle, Impayée (rien réglé après la date butoir), Due ; relances au gérant à J+30, J+60, J+90 après la date butoir (`dues.reminder_offsets`) et à la demande ; à J+90 (`dues.alert_offset`), la Présidente est alertée qu'elle **peut** suspendre le centre. Aucune suspension automatique.
10. **Messages groupés** : filtre portefeuille (CGA par défaut ; Cabinet ou tous avec `members.cabinet.view`), régimes, secteurs, honoraires impayés (échéance d'honoraires en retard), dossiers choisis ; dossiers actifs ou en radiation. Le nombre de destinataires est compté en direct par le serveur (`POST /cga/campaigns/preview`), avec les SMS nécessaires et restants. Au-delà de `campaign.approval_threshold` destinataires (50), l'envoi d'un agent attend la validation du gérant, qui peut refuser avec un motif ; **envoyé par le gérant lui-même, il part aussitôt, validé par lui** (03-APPFLOW §4.3). Avec le SMS, le quota restant doit couvrir tous les destinataires. Envoi aux accès en ligne des dossiers ; par e-mail aux dossiers sans accès. Les modèles de message (« Rappel d'échéance », « Honoraires impayés », « Pièces de clôture ») sont des textes de départ, modifiables, rangés dans les traductions de l'interface ; l'assistant accepte `?membres=…&modele=…` pour le bouton « Envoyer un rappel » de `/cga/echeances` (écran de l'étape 4.7).
11. **Facture de cotisation (étape 6.9)** : « Facturer » depuis le suivi réseau émet une seule facture par cotisation, du montant dû, au nom du centre débiteur (`billed_organization_id`), numérotée et certifiée selon le mode FNE du CNCGA (`dues.tax_rate`, 0 % par défaut : cotisation hors taxe). Elle n'est visible du centre qu'une fois certifiée (RG-FNE-01) ; elle ne se modifie plus (avoir). Le gérant du centre est notifié à la certification. Le reçu et la facture, documents du CNCGA, sont remis au centre débiteur hors cloisonnement, pour ses seules cotisations.
12. **Fiche du CNCGA (étape 6.9)** : le CNCGA, émetteur des reçus (signés par la Présidente) et des factures de cotisation, complète sa fiche comme un CGA (`/network/profile`, onglet « Identité du CNCGA » de `/cncga/parametres`), **sans agrément ni expert-comptable référent** (champs refusés). Droits `network_profile.view` et `network_profile.manage` à la Présidente et au gestionnaire du réseau (le bureau et le chargé des cotisations n'ont pas « Paramètres », 03-APPFLOW §3.1), ajustables en base. Option retenue le 02/11/2026 lors du développement (onglet dédié du CNCGA).

## Preuve

`tests/Feature/Notifications/` (NotificationEngineTest, MessagingProvidersTest, NotificationCatalogTest, MessageCampaignsTest), `tests/Feature/Dues/NetworkDuesTest.php`, `tests/Feature/Organizations/NetworkProfileTest.php` (noms contenant RG-NOT-01 à 05, RG-COM-01 et 02, RG-COT-01 à 06, RG-FNE-01, RG-ORG-04) ; écrans : `frontend/src/features/{notifications,platform,dues,communications,cncga-settings}/**/*.test.tsx`.

## Points ouverts

- Montant, date butoir, prorata et taux de TVA de la cotisation : à confirmer par le CNCGA (paramètres réseau).
- Champs exigés de la fiche du CNCGA (ceux d'un centre, hors agrément et expert-comptable) : à confirmer par le CNCGA.
- Export Excel de `/cncga/cotisations` et « Facturer » une sélection : prévus avec les exports du Lot 8 (étape 8.6) ; la facturation se fait ligne par ligne d'ici là.
- Fréquence des rappels d'honoraires et de pièces : à confirmer par DC-KNOWING.
- Modèle du reçu de cotisation : provisoire, à remplacer par le modèle définitif (écran des modèles).
