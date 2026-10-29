# 0004 — Saisie des montants et déclarations des obligations

- **Statut** : adoptée (réponses de l'équipe projet du 29/10/2026)
- **Date** : 29/10/2026
- **Étape** : 06-IMPLEMENTATION-PLAN, Lot 4, étape 4.4
- **Références** : 01-PRD F-OBL-04, F-OBL-05, RG-OBL-07, RG-OBL-08, RG-OBL-09 ; 03-APPFLOW §4 (onglet Obligations) et §5.2 (`/adherent/obligations`) ; 05-BACKEND-SCHEMA §7 (`obligation_instances`, `obligation_payments`)

## Contexte

Le PRD fixe le calcul du statut d'un paiement (RG-OBL-07), le rôle du CGA comme référent des montants dus (RG-OBL-08) et la déclaration par l'adhérent, que le CGA « confirme ou corrige » (RG-OBL-09). Quatre cas n'y sont pas tranchés.

## Décisions

1. **Paiement supérieur au reste dû : refusé** (409 `obligation_payment_exceeds_balance`), comme pour le droit d'adhésion. L'agent corrige d'abord le montant dû s'il le faut.
2. **Montant dû inconnu : le CGA le saisit d'abord.** Un paiement ne s'enregistre ni ne se confirme sur une échéance sans montant dû (409 `obligation_amount_unknown`). L'adhérent peut tout de même déclarer son paiement : la déclaration attend, et le CGA saisit le montant dû avant de la confirmer.
3. **Le CGA confirme, corrige ou rejette** la déclaration de l'adhérent : il la confirme telle quelle, la confirme avec un montant ou une date corrigés, ou la rejette avec un motif (la ligne revient « À faire » ou « À payer »).
4. **Preuve facultative sur les paiements seulement**, comme le prévoit le schéma (`obligation_payments.proof_file_id`). Une déclaration « faite » n'a pas de pièce jointe.

## Preuve

Tests de l'étape 4.4 dans `tests/Feature/Obligations/` (noms contenant RG-OBL-07, RG-OBL-08, RG-OBL-09).
