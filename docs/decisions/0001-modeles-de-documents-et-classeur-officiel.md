# 0001 — Modèles de documents et classeur officiel

- **Statut** : proposée — en attente du contrôle visuel de la QA (Word, Excel, LibreOffice)
- **Date** : 20/10/2026
- **Étape** : 06-IMPLEMENTATION-PLAN, Lot 0, étape 0.11
- **Références** : 02-TRD §8.1 à §8.3, 01-PRD RG-DOC-01 à RG-DOC-06

## Contexte

Les quatre modèles de la maquette (`contrat`, `attestation`, `rapportWord`, `rapport` .xlsx) sont la base des documents produits par la plateforme. Ils contenaient des données réelles et devaient être nettoyés, balisés `${…}`, puis éprouvés sur la chaîne de production : remplissage, conversion DOCX → PDF par LibreOffice installé sur le serveur, remplissage du classeur officiel SP-CGA de 14 feuilles. Le TRD laisse une décision ouverte pour ce classeur : PhpSpreadsheet (plan A) ou écriture directe du XML (plan B).

## Décision

1. **Modèles Word** : PhpWord (TemplateProcessor), variables `${…}`, lignes répétées par `cloneRow`, conversion PDF par LibreOffice (`--headless`, un profil par worker, délai `pdf.timeout_seconds`).
2. **Classeur officiel** : **plan A, PhpSpreadsheet**. Le plan B (`OfficialWorkbookWriter`) n'est pas développé ; il ne le sera que si la QA constate un écart de rendu.

## Ce qui a été fait

### Nettoyage (aucune donnée réelle)

| Modèle | Fichier | Données retirées |
|---|---|---|
| Contrat d'adhésion | `backend/resources/templates/membership_contract.docx` | Identité du centre et de son gérant, identité complète d'un adhérent, lieu et date de signature, en-têtes |
| Attestation d'adhésion | `membership_certificate.docx` | Identité du centre et de l'adhérent, numéros, logo du centre (image et chemin du fichier d'origine), filigrane de l'année, pied de page, nom de l'adhérent en WordArt sur la page de couverture |
| Rapport trimestriel (Word) | `quarterly_report_docx.docx` | Identité du centre, dirigeants, salariés, occasionnels, moyens matériels, montants des recettes fiscales, signataire |
| Rapport trimestriel (classeur officiel) | `quarterly_report_xlsx.xlsx` | Identité du centre, personnel, listes d'adhérents de tous les CDI, montants, noms des CDI, résultats de formules en cache ; table des chaînes partagées purgée (671 → 172 chaînes) |

Les propriétés des fichiers (auteur, dates d'origine) sont neutralisées. Aucune mention obligatoire n'a été retirée (RG-DOC-01) : seules les données ont été remplacées.

Le test `backend/tests/Feature/Documents/DocumentTemplatesTest.php` cherche dans toutes les parties des fichiers (texte, attributs, en-têtes, pieds de page, propriétés, feuilles) les noms, numéros et coordonnées de la maquette d'origine ; il échoue si l'un d'eux réapparaît.

### Balisage

- Les variables de chaque modèle sont déclarées dans `backend/config/documents.php` ; un test vérifie que chaque fichier porte exactement ces variables.
- **Images** : signature, cachet et QR de vérification sont insérés à la place de leur variable, dans l'espace laissé pour la signature manuscrite (le nombre de pages d'origine est conservé : attestation 2, contrat 4, rapport 4). Le **logo** de l'attestation garde son cadre flottant d'origine (230 × 83 px) : le modèle contient une image neutre `word/media/cga_logo.jpeg`, remplacée par le logo du centre, ajusté au cadre sans déformation.
- **Filigrane** de l'attestation : l'année devient `${certificate_year}` (RG-DOC-06, une attestation par année civile).
- **RG-DOC-02** : la mention « Signé électroniquement » du rapport Word est retirée ; signature et cachet sont des images.
- **Classeur** : la première ligne de chaque bloc (adhérents d'un CDI, dirigeants, salariés, occasionnels) porte les variables d'identification ; les colonnes de montants restent vides, leur correspondance sera fixée à l'étape du rapport trimestriel (lot 3), avec la duplication des blocs par CDI (02-TRD §8.3).

### Preuves sur le poste de développement

| Contrôle | Résultat |
|---|---|
| Chaîne DOCX → PDF (LibreOffice 26.2.6) | Les 3 modèles Word et le classeur sont convertis en PDF |
| Durée de conversion | 1ʳᵉ conversion 18 à 37 s (création du profil LibreOffice), puis **7 à 10 s** par document sur ce poste lent ; la cible de 10 s (02-TRD §8.2) est à confirmer sur le serveur de recette |
| Classeur avec PhpSpreadsheet 5.10 | Relu et réenregistré avec ses **14 feuilles** ; les **3 dessins** (titre WordArt de la page de garde, cadres) sont repris à l'identique (seule la déclaration XML change) ; les formules sont conservées et recalculées à l'ouverture (`fullCalcOnLoad`) ; `calcChain.xml` disparaît, comme prévu par les tableurs qui le reconstruisent |
| Rendu LibreOffice | Page de garde du classeur, attestation, contrat et rapport Word contrôlés visuellement sur la première page |

## Contrôle visuel attendu de la QA

Produire les échantillons (données fictives), serveur ou poste avec LibreOffice :

```bash
cd backend
php artisan documents:samples            # storage/app/private/samples
```

Puis, pour chaque modèle, ouvrir le DOCX ou le XLSX dans **Word** ou **Excel** et dans **LibreOffice**, ainsi que le PDF, et comparer à la maquette d'origine :

- [ ] en-têtes, logo dans son cadre, filigrane de l'année, pieds de page ;
- [ ] mentions obligatoires intactes (RG-DOC-01), aucun texte « ${…} » visible ;
- [ ] signature, cachet et QR lisibles, sur la même page que le nom du signataire ; le QR se lit avec un téléphone ;
- [ ] tableaux répétés du rapport Word (dirigeants, salariés, occasionnels, mouvements) ;
- [ ] classeur : page de garde (titre WordArt), 14 onglets, largeurs de colonnes, cellules fusionnées, bordures, formules recalculées, aperçu avant impression.

Tout écart sur le classeur rouvre le plan B ; tout écart sur un modèle Word se corrige dans le modèle.

## Points ouverts

- Validation des modèles par le CNCGA (et par le SP-CGA pour toute évolution de fond, RG-DOC-01) avant leur chargement dans `document_templates` (F-ADM-02).
- Tailles des images (`config/documents.php`) : signature 96 × 40, cachet 56 × 56, QR 64 × 64 pixels, retenues pour garder la mise en page d'origine ; à confirmer par la QA, notamment la lisibilité du QR imprimé.
- Graphiques du rapport modernisé : étape 0.12 (décision T-21).

## Conséquences

- `phpoffice/phpword`, `phpoffice/phpspreadsheet` et `endroid/qr-code` (02-TRD §4.2) sont installés.
- `PdfConverter` (pilote `libreoffice`, `PDF_CONVERTER`), `DocxTemplateRenderer` et `OfficialWorkbookFiller` sont prêts pour le Job `GenerateDocument` (étape des documents) et le rapport trimestriel.
- Les workers de la file `documents` reçoivent chacun leur profil LibreOffice (`LIBREOFFICE_PROFILE_SLOT`, `infra/supervisor`).
