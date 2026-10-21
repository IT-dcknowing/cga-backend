# 0003 — Une seule radiation en cours par dossier

- **Statut** : adoptée — à confirmer par le Directeur
- **Date** : 21/10/2026
- **Étape** : 06-IMPLEMENTATION-PLAN, Lot 2, étape 2.14
- **Références** : 05-BACKEND-SCHEMA §5 (`radiations`), 01-PRD RG-PTF-03, RG-PTF-04, RG-ADH-17, RG-ADH-18

## Contexte

Le schéma prévoit sur `radiations.member_id` un index unique partiel `WHERE cancelled_at IS NULL` : une seule radiation non annulée par dossier, pour toute sa vie.

Or le PRD permet ce parcours :

1. un adhérent du portefeuille CGA est reclassé au réel ; sa radiation est validée avec l'issue « basculer dans le portefeuille Cabinet » (RG-PTF-04) ;
2. revenu au RME ou au TEE, il signe un nouveau contrat CGA et revient au portefeuille CGA (RG-PTF-03) ;
3. plus tard, il est de nouveau radié.

Au point 3, la nouvelle radiation se heurterait à l'index (la première, validée, n'est pas annulée) : erreur 500, radiation impossible.

## Décision

L'index ne porte plus que sur les demandes en cours : `radiations_member_pending_unique ON radiations (member_id) WHERE status = 'requested'` (migration `2026_10_22_100004`). Un dossier n'a jamais deux radiations en cours ; il peut avoir plusieurs radiations validées au fil de ses contrats CGA successifs.

Conséquences dans le code :

- la validation ne vise que la radiation en cours ;
- l'annulation vise la dernière radiation non annulée, et refuse (409 `radiation_not_found`) une bascule vers le Cabinet dont le dossier est depuis revenu au portefeuille CGA : elle ne peut plus être défaite ;
- la fiche n'affiche plus cette ancienne bascule comme « radiation en vigueur ».

## Ce qui reste inchangé

Un adhérent radié (issue « clore le dossier ») qui revient fait toujours une nouvelle adhésion, avec un nouveau dossier (RG-ADH-13) : ce cas n'est pas concerné.

## Preuve

`tests/Feature/Members/MoveToCgaTest.php` : aller CGA → Cabinet par radiation validée, retour au portefeuille CGA, ancienne radiation non annulable, nouvelle radiation validée.
