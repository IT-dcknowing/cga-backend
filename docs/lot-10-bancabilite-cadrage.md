# Lot 10 — Bancabilité : cadrage technique initial

## 1. Périmètre fonctionnel identifié

Le lot 10 doit couvrir :

- le consentement explicite de l'adhérent à la constitution et au partage de son dossier bancaire ;
- la collecte des 13 pièces justificatives, leur statut et leurs dates d'expiration ;
- le calcul d'un score de bancabilité sur 100 ;
- des critères et sous-critères configurables par la CNCGA ;
- le référentiel des banques partenaires ;
- la création et le suivi des demandes de financement ;
- la génération d'un dossier bancaire PDF ;
- les parcours et autorisations des espaces adhérent, CGA et CNCGA.

Ce document est un cadrage technique de départ. Il ne vaut pas validation des règles métier par la CNCGA.

## 2. État de l'existant vérifié sur la branche de référence

Branche de référence : `front/reprise-ecrans-acces-registre`.

Éléments réutilisables repérés :

- `organizations.banking_enabled` : indicateur existant d'activation de la fonctionnalité bancaire au niveau de l'organisation ;
- `DocumentType::BankingFile` : type de document déjà réservé au dossier bancaire ;
- domaine `Members` : dossier adhérent, portefeuille, données d'identification, activité et revenus ;
- table `member_pieces` : pièces déjà associées à un adhérent, avec fichier, statut, date de réception et date d'expiration ;
- `DocumentType` et `generated_documents` : infrastructure de génération, versionnement, stockage PDF/DOCX/XLSX et empreinte SHA-256 ;
- génération de documents : actions et composants réutilisables dans `app/Domain/Documents`.

Limites importantes :

- les types de pièces actuellement exposés par `PieceType` sont RCCM, DFE, pièce d'identité, statuts, plan de localisation et contrat CGA signé ; ils ne représentent pas la liste bancaire des 13 justificatifs ;
- aucun domaine Bancabilité, catalogue de banques partenaires, moteur de score bancaire ou workflow de demandes de financement n'a été identifié dans les répertoires métier inspectés ;
- le champ `banking_enabled` est un indicateur d'organisation, pas un consentement individuel de l'adhérent.

## 3. Principes techniques à respecter

1. **Cloisonnement multi-organisation** : toutes les données opérationnelles doivent être rattachées à l'organisation et au dossier adhérent ; les accès inter-CGA ne doivent jamais être implicites.
2. **Consentement traçable** : distinguer l'activation de la fonctionnalité par le CGA du consentement de l'adhérent ; conserver la preuve, la version du texte accepté, l'horodatage et le retrait éventuel.
3. **Confidentialité bancaire** : une banque ne reçoit que les données et pièces autorisées pour la demande concernée ; aucun partage automatique à toutes les banques.
4. **Pièces versionnées** : conserver le statut, les dates utiles, le fichier courant et l'historique des remplacements ; signaler les pièces expirées sans les déclarer valides.
5. **Score explicable** : stocker les critères, sous-critères, pondérations et résultats utilisés pour chaque calcul ; permettre de comprendre le score et sa date de calcul.
6. **Configuration gouvernée** : la CNCGA administre les référentiels et versions de critères ; les modifications ne doivent pas réécrire les scores historiques.
7. **Dossier PDF reproductible** : conserver la version du modèle, les références des pièces incluses, la date de génération et une empreinte du document.
8. **Audit** : journaliser le consentement, les consultations sensibles, les changements de configuration, les transmissions à une banque et les décisions sur une demande.

## 4. Découpage proposé

| Sous-lot | Livrable | Dépendance métier |
|---|---|---|
| 10.1 | Modèle de dossier bancaire, activation CGA et consentement adhérent | Texte de consentement, finalités et destinataires autorisés |
| 10.2 | Référentiel des 13 pièces, dépôt, validation, expiration et relances | Liste exacte des pièces, caractère obligatoire et règles d'expiration |
| 10.3 | Critères, sous-critères, versions et calcul du score /100 | Formule, pondérations, seuils, règles de données manquantes |
| 10.4 | Banques partenaires et habilitations de partage | Liste des banques, conditions et périmètre des données partagées |
| 10.5 | Demandes de financement et cycle de vie | Statuts, acteurs, validations, motifs de rejet et suivi |
| 10.6 | Composition et génération du dossier bancaire PDF | Plan du dossier, pièces incluses, modèle et mentions |
| 10.7 | API, permissions et parcours des espaces adhérent/CGA/CNCGA | Matrice d'accès et écrans attendus |
| 10.8 | Tests, audit, sécurité et recette métier | Scénarios de recette et validation CNCGA |

## 5. Décisions métier à obtenir avant les traitements irréversibles ou les calculs affichés

### Consentement
- texte exact et version du consentement ;
- finalités autorisées ;
- destinataires (CGA, CNCGA, banques sélectionnées) ;
- possibilité et effet du retrait ;
- preuve à conserver et durée de conservation.

### Justificatifs
- intitulé exact des 13 pièces ;
- pièces obligatoires selon le statut ou le profil de l'entreprise ;
- formats et tailles autorisés ;
- durée de validité et point de départ de chaque expiration ;
- pièces renouvelables, pièces facultatives et pièces de remplacement.

### Score
- critères et sous-critères ;
- poids totalisant 100 ou règle de normalisation ;
- échelle de notation par critère ;
- traitement des données absentes, incohérentes ou anciennes ;
- seuils d'interprétation et mentions affichées ;
- fréquence de recalcul et gel des versions historiques.

### Banques et demandes
- banques partenaires et contacts/référentiels ;
- critères d'éligibilité propres à chaque banque ;
- partage individuel ou groupé et autorisation préalable ;
- statuts, transitions, délais, acteurs et motifs de décision ;
- traçabilité des transmissions et accusés de réception.

### PDF
- ordre des sections et pièces ;
- données affichées ou masquées ;
- modèle approuvé, signature/cachet éventuels ;
- durée de validité et règles de régénération.

## 6. Règle de livraison

Le développement peut avancer sur les fondations techniques non ambiguës et les tests d'isolation. Les listes de pièces, règles d'expiration, formules de score, seuils, textes de consentement, banques et workflows ne doivent pas être inventés. Ils seront activés après validation CNCGA.

Aucune donnée financière ou pièce justificative ne doit être transmise à une banque par défaut. Un score ne doit pas être présenté comme une décision de crédit ni comme une garantie d'obtention de financement.
