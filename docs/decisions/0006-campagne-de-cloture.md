# 0006 — Campagne de clôture : livrable, ordre des étapes, calendrier

- **Statut** : adoptée (réponses de l'équipe projet du 29/10/2026)
- **Date** : 29/10/2026
- **Étape** : 06-IMPLEMENTATION-PLAN, Lot 4, étape 4.10
- **Références** : 01-PRD F-OBL-08, RG-OBL-10 à 12 ; 03-APPFLOW `/cga/cloture`, onglet Clôture du dossier ; 05-BACKEND-SCHEMA §7 (`closing_campaigns`, `closing_campaign_items`), §16 (paramètres `closing.*`)

## Contexte

Le PRD décrit les 4 étapes de la campagne et leurs dates par défaut. Il ne dit ni comment choisir entre SMT et états financiers pour un dossier, ni si les étapes se font dans l'ordre.

## Décisions

1. **Livrable proposé selon le régime, modifiable** : TEE et RME → SMT ; RSI et RNI → états financiers. Le CGA peut changer le livrable d'un dossier.
2. **Étapes dans l'ordre** : une étape ne se marque faite que si la précédente l'est (409 `closing_step_out_of_order`). Une étape faite ne se refait pas.
3. **Calendrier en deux paramètres** : `closing.calendar` (pièces, régularisation, finalisation ; réseau, ajustable par chaque CGA) et `closing.filing_date` (dépôt légal ; réseau seulement, réglé par le CNCGA). Le schéma prévoyait une seule clé « ajustable par CGA sauf filing » ; deux clés rendent la règle impossible à contourner.
4. **Ouverture rejouable** : relancer l'ouverture d'un exercice ajoute les dossiers devenus actifs depuis, sans doubler un suivi ni changer le calendrier figé.
5. **Dépôt légal** : la référence et l'accusé sont exigés (03-APPFLOW : « dépôt : référence et accusé »). Les autres étapes n'ont pas de pièce jointe : le schéma n'en prévoit pas. L'auteur de chaque étape figure au journal des modifications.
6. **Rappels** : `reminders:closing` calcule chaque jour les rappels dus (J-15, J-5, puis tous les 7 jours de retard, paramétrables) et le relevé des retards. Il les remet au moteur de notifications sous forme d'événements ; l'envoi est branché au Lot 6.

## Preuve

`tests/Feature/Closing/` et `tests/Unit/Closing/` (noms contenant RG-OBL-10, RG-OBL-11, RG-OBL-12).
