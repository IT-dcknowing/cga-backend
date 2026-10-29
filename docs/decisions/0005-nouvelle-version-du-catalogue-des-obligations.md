# 0005 — Application d'une nouvelle version du catalogue des obligations

- **Statut** : adoptée (réponses de l'équipe projet du 29/10/2026)
- **Date** : 29/10/2026
- **Étape** : 06-IMPLEMENTATION-PLAN, Lot 4, étape 4.9
- **Références** : 01-PRD RG-DATA-04, RG-OBL-02, F-OBL-01 ; 03-APPFLOW `/cncga/parametres` (« Catalogue des obligations ») ; décision 0004

## Contexte

RG-DATA-04 prévoit qu'une nouvelle version du catalogue « propose de mettre à jour les obligations des adhérents (aperçu des ajouts, retraits et changements d'échéance avant confirmation) » et que « les échéances passées ne sont pas modifiées ». Le sort des obligations nouvelles et retirées n'y est pas fixé.

## Décisions

1. **Obligation nouvelle applicable à un dossier : proposée au CGA**, qui la confirme ou la retire, comme à l'inscription (RG-OBL-02).
2. **Obligation retirée de la version : fin automatique** à l'application de la version, après l'aperçu confirmé par le CNCGA. L'obligation du dossier prend fin le jour de l'application ; ses échéances à venir non commencées sont annulées.
3. **Obligation conservée** (même code) : le dossier la garde, rattachée à la nouvelle version. Si la périodicité ou la nature change, ses échéances à venir non commencées sont annulées et la génération de nuit crée les nouvelles. Si seule la règle d'échéance change, leur date est recalculée.
4. **Échéances passées ou commencées inchangées** : une échéance échue, faite, payée en tout ou partie ou déclarée par l'adhérent n'est jamais modifiée.
5. **Aperçu sans donnée nominative** : le CNCGA ne voit que des nombres (obligations, dossiers, centres concernés), jamais le nom d'un adhérent.

Une échéance « non commencée » : à venir, encore à faire, à payer ou non établie, sans rien de payé ni de déclaré.

## Preuve

`tests/Feature/Obligations/CatalogVersionTest.php` (passage du catalogue provisoire à une nouvelle version sur 50 adhérents de deux centres).
