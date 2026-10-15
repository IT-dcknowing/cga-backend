# 04 — UX / UI design brief · CGA Connect

> Version 1.1 · 15/10/2026 (portefeuilles, FNE, rapport modernisé, clôture, habilitations) · Références : maquette `cga-connect-v4-1.html` (identité visuelle validée), 03-APPFLOW (écrans), 02-TRD (Tailwind CSS v4 + shadcn/ui).
> Objectif : que toute interface construite ait la même apparence que la maquette, sans improvisation.

---

## 1. Ambiance

**Institutionnel, sobre, rassurant, efficace.** CGA Connect est un outil de travail pour des professionnels de la comptabilité et de la fiscalité, et un service officiel pour des chefs d'entreprise souvent peu familiers du numérique.

| Oui | Non |
|---|---|
| Vert profond institutionnel, beaucoup de blanc, cartes nettes | Dégradés, néons, effets de verre, ombres lourdes |
| Données lisibles d'abord : chiffres en gras, tableaux aérés | Illustrations décoratives, animations gratuites |
| Vocabulaire métier exact (adhérent, radiation, échéance, CDI) | Jargon technique ou anglais (« dashboard », « submit ») |
| Une action principale visible par écran | Plusieurs boutons primaires concurrents |
| Statuts toujours accompagnés d'un mot, pas seulement d'une couleur | Codes couleur sans texte |

Ton des textes : vouvoiement, phrases courtes, verbes d'action sur les boutons (« Valider l'adhésion », « Enregistrer le paiement »). Messages d'erreur qui disent quoi faire : « Le NCC doit contenir 7 chiffres suivis d'une lettre (ex. 1444351 F). »

---

## 2. Couleurs (jetons)

Reprises de la maquette, complétées pour les états manquants et l'accessibilité.

| Jeton | Valeur | Usage |
|---|---|---|
| `--color-primary` | `#1E6A4E` | Boutons principaux, liens, éléments actifs du menu, barres de progression |
| `--color-primary-dark` | `#144C37` | Survol des boutons principaux, en-tête de l'application, texte des badges verts |
| `--color-primary-soft` | `#E7F0EB` | Fond des badges verts, fond de l'élément de menu actif, lignes sélectionnées |
| `--color-accent` | `#E5732A` | Liseré décoratif sous les titres, compteurs d'alertes, bordure gauche des alertes — **jamais pour du texte sur blanc** |
| `--color-accent-text` | `#9A4A12` | Texte orange (badges « à traiter ») |
| `--color-accent-soft` | `#FBEADD` | Fond des badges et bandeaux orange |
| `--color-danger` | `#B42318` | Erreurs, actions irréversibles, badge « Annulé », bandeau « Suspendu » |
| `--color-danger-soft` | `#FDECEA` | Fond des bandeaux d'erreur |
| `--color-info` | `#2B4C7E` | Étiquettes de plateforme externe (e-impôts, e-CNPS), liens secondaires |
| `--color-info-soft` | `#EDF2FA` | Fond des étiquettes de plateforme |
| `--color-ink` | `#17251F` | Texte principal |
| `--color-muted` | `#5C6B62` | Texte secondaire, libellés, notes |
| `--color-border` | `#E1E6E0` | Bordures, séparateurs |
| `--color-bg` | `#F4F6F2` | Fond de l'application |
| `--color-card` | `#FFFFFF` | Cartes, tableaux, modales |
| `--color-neutral-soft` | `#EEF0ED` | Fond des badges gris |

Contrastes vérifiés (WCAG AA, texte normal ≥ 4,5:1) :
- `ink` sur `bg` ;
- `muted` sur blanc ;
- `primary-dark` sur `primary-soft` ;
- `accent-text` sur `accent-soft` ;
- blanc sur `primary` ;
- blanc sur `danger`.

> Les documents officiels (contrat, attestation, rapport) gardent l'identité de chaque CGA (logo, couleurs de ses modèles). La charte « bleu marine et or » du CNCGA ne s'applique qu'aux documents institutionnels du CNCGA (reçus de cotisation, plaquette statistique), pas à l'interface.

Mise en œuvre Tailwind v4 : ces jetons sont déclarés dans `@theme` (`--color-primary: #1E6A4E;` …) et mappés sur les variables shadcn (`--primary`, `--destructive`, `--muted`, `--border`, `--ring` = `primary`).

### 2.1 Règle des statuts (`StatusBadge`)

| Ton | Fond / texte | Statuts |
|---|---|---|
| Vert (favorable) | `primary-soft` / `primary-dark` | Actif, À jour, Payé, Faite, Établie, Transmis, Accusé reçu, Traitée, Confirmé, Valide, Inscrit, Présent, Complet, Certifiée (FNE), Étape faite, Synchronisé |
| Orange (à traiter) | `accent-soft` / `accent-text` | À faire, À payer, Partiellement payé, Partielle, Impayé (n mois), En retard, En attente de confirmation, En examen, Ouverte, Pièces en attente, Liste d'attente, Brouillon, Reclassé au réel — à radier, Radiation en cours, En attente de certification, Incomplet |
| Rouge (bloquant) | `danger-soft` / `danger` | Radié, Annulé, Suspendu, Rejeté, Rejetée (FNE), Impayée (après la date butoir), Expirée, Attestation bloquée, Hors délai réglementaire, Synchronisation en échec |
| Gris (neutre) | `neutral-soft` / `muted` | Non applicable, Non abonné, Essai, Clôturée, Clos, Désinscrit, régimes (RME, TEE, RSI, RNI), Ancien n° |
| Bleu (référence externe) | `info-soft` / `info` | Plateformes (⇄ e-impôts, ⇄ e-CNPS, ⇄ GUDEF) |

La correspondance statut → ton est **centralisée** dans `lib/status.ts`. Aucun composant ne choisit sa couleur lui-même.

### 2.2 Portefeuilles et étiquettes internes
- **PortfolioBadge** : « CGA » = contour `primary`, texte `primary-dark`, fond blanc ; « Cabinet » = contour `info`, texte `info`, fond blanc. Toujours à côté du numéro du dossier (liste, fiche, résultats de recherche). Ce n'est pas un statut : pas de fond plein.
- **Étiquette « Accessoire n »** (apporteur) : pastille grise à bordure pointillée avec icône `Handshake`, uniquement sur un dossier du portefeuille CGA, affichée seulement si l'utilisateur a le droit ; jamais imprimée sur un document.
- **Sélecteur de portefeuille** (liste des adhérents, tableau de bord) : groupe segmenté « Portefeuille CGA · Portefeuille Cabinet · Tous », mémorisé par utilisateur (stockage local, sans donnée sensible).

---

## 3. Typographie

- Police : **Archivo** (Google Fonts, graisses 400, 600, 700, 800), repli `ui-sans-serif, system-ui, sans-serif`. Chiffres tabulaires (`font-variant-numeric: tabular-nums`) dans les tableaux et indicateurs.

| Style | Taille / interligne | Graisse | Usage |
|---|---|---|---|
| Titre de page | 24 / 32 px (20 px sur mobile) | 800 | Une fois par écran |
| Titre de carte | 16 / 24 px | 700 | En-tête des cartes |
| Sous-titre de section | 11 / 16 px, majuscules, espacement 0,4 px | 800, `muted` | Sous-parties des cartes (« DÉCLARATIONS À VENIR ») |
| Texte | 15 / 22 px | 400 | Contenu |
| Texte de tableau | 14 / 20 px | 400 (600 pour la 1re colonne) | Tableaux |
| Libellé de champ | 13 / 18 px | 600, `muted` | Formulaires |
| Note | 12,5 / 18 px | 400, `muted` | Aides, mentions |
| Valeur d'indicateur | 26 / 32 px | 800 (`danger` si alerte) | Cartes d'indicateurs |
| Badge | 11,5 / 16 px | 700 | Statuts |

Montants : `1 234 567 F` (espace insécable comme séparateur des milliers, « F » après le nombre). Grands montants dans les indicateurs : `4,8 M F`, `15,3 Mds F`. Dates : `14/01/2026` dans les tableaux, `14 janvier 2026` dans les phrases.

---

## 4. Espacements, formes, élévation

- Grille de 4 px ; espacements courants : 8, 12, 16, 24, 32.
- Rayons : 6 px (boutons, champs), 8 px (cartes, modales), 5 px (badges).
- Bordures 1 px `border` ; ombre unique et légère pour les cartes : `0 1px 2px rgba(23,37,31,.06)`.
- Largeur maximale du contenu : 1280 px. Gouttière de 16 px sur mobile, 24 px au-delà.
- Cibles tactiles ≥ 44 × 44 px sur mobile.

---

## 5. Structure des écrans

### 5.1 Coque de l'application (espaces CNCGA et CGA, ordinateur ≥ 1024 px)

```
┌──────────────────────────────────────────────────────────────────────┐
│ EN-TÊTE (fond primary-dark, texte blanc, 56 px)                      │
│ [Logo CGA Connect]  Nom de l'organisation · Rôle     🔔 3  [Prénom ▾]│
├───────────────┬──────────────────────────────────────────────────────┤
│ MENU LATÉRAL  │ Fil d'Ariane (muted)                                 │
│ 240 px, blanc │ Titre de page                       [Action principale]│
│ ▸ Tableau…    │ ┌─ Bandeau éventuel (suspendu, fiche incomplète) ──┐ │
│ ▸ Adhérents   │ └──────────────────────────────────────────────────┘ │
│ ▸ Échéances   │ Contenu : cartes et tableaux sur fond bg            │
│ …             │                                                      │
└───────────────┴──────────────────────────────────────────────────────┘
```
- Élément de menu actif : fond `primary-soft`, texte `primary-dark`, barre verticale de 3 px `primary` à gauche.
- Menu « Prénom ▾ » : Mon profil · Changer d'espace · Déconnexion.
- Tablette (768–1023 px) : menu latéral replié en icônes, déplié au survol ou au toucher.

### 5.2 Espace adhérent (mobile d'abord, 360–767 px)
- En-tête compact : nom de l'entreprise (tronqué), cloche.
- Barre de navigation basse fixe (5 entrées, icône + libellé 11 px, actif en `primary`).
- Contenu en une colonne, cartes pleine largeur, tableaux transformés en **listes de cartes** (une carte par ligne : libellé en gras, montant à droite, statut en badge, date en note).
- Sur ordinateur : même coque que les autres espaces, menu latéral.

### 5.3 Pages publiques (connexion, activation, vérification)
- Fond `bg`, carte centrée de 420 px maximum, logo CGA Connect au-dessus, mention « République de Côte d'Ivoire · Réseau des Centres de Gestion Agréés » en note.
- La page de vérification affiche un grand bandeau d'état (vert « Document authentique et valide » / rouge « Document annulé »), puis les 5 informations en liste clé / valeur.

---

## 6. Composants (shadcn/ui + composants partagés)

| Composant | Spécification |
|---|---|
| **Button** | Variantes : `primary` (fond primary, texte blanc, 600), `secondary` / « ghost » de la maquette (fond blanc, bordure border, texte ink), `danger` (fond danger), `link`. Tailles : `md` 40 px de haut, `sm` 32 px. Icône à gauche facultative. État chargement : indicateur + libellé conservé, bouton désactivé |
| **KpiCard** | Libellé (13 px muted), valeur (26 px 800), sous-texte (12,5 px muted), variante `alert` (valeur en `danger`). Cliquable si elle mène à une liste filtrée (curseur + survol) |
| **Card** | En-tête : titre + zone d'actions à droite ; corps avec marge 16 px, ou sans marge (`flush`) pour les tableaux |
| **DataTable** | En-têtes 12 px majuscules `muted` sur fond `bg`, lignes de 48 px, survol `primary-soft` à 40 %, 1re colonne en 600 ; tri par clic sur l'en-tête ; pagination en bas (« 1–25 sur 142 » + précédent / suivant) ; barre au-dessus : recherche, filtres (menus déroulants), bouton Exporter ; sur mobile : liste de cartes |
| **StatusBadge** | §2.1 |
| **PlatformTag** | Étiquette bleue « ⇄ e-impôts » |
| **PeriodSelector** | Groupe de 4 boutons segmentés Jour / Mois / Année / Période ; « Période » ouvre deux sélecteurs de date ; libellé de la période sous le groupe (« Vue : octobre 2026 ») |
| **ProgressBar** | 8 px, fond `border`, remplissage `primary` (100 %) ou `accent` (< 100 %), pourcentage à droite |
| **Stepper** | Assistant d'inscription : étapes numérotées (actif `primary`, fait ✓) |
| **Form fields** | Libellé au-dessus, astérisque `danger` pour l'obligatoire, aide en note sous le champ, erreur en `danger` 12,5 px sous le champ avec bordure `danger` |
| **FileUpload** | Zone de dépôt en pointillés + bouton « Choisir un fichier » ; affiche nom, taille, aperçu (image / PDF) ; formats et taille maximale rappelés |
| **Modal (Dialog)** | 560 px (formulaires) ou 720 px (aperçus) ; plein écran sur mobile ; titre, sous-titre `muted`, corps, pied avec action principale à droite et « Annuler » à gauche |
| **ConfirmDialog** | Pour les actions irréversibles : liste des effets, case « Je confirme » si l'effet est majeur (radiation, suspension), bouton `danger` |
| **Banner** | Pleine largeur dans le contenu, bordure gauche de 4 px : orange (avertissement), rouge (bloquant), vert (succès) ; icône + texte + action éventuelle |
| **Toast** (sonner) | En bas à droite (en haut sur mobile), 4 s, ✓ vert ou ✕ rouge |
| **EmptyState** | Icône lucide 40 px `muted`, phrase, action principale |
| **Skeleton** | Blocs gris animés à la forme du contenu |
| **Tabs** | Soulignement 2 px `primary` pour l'onglet actif ; défilement horizontal sur mobile |
| **ConversationThread** | Messages en bulles : demandeur à gauche (fond blanc), organisation à droite (fond `primary-soft`) ; notes internes : fond `accent-soft` + icône 🔒 « Note interne » ; messages système en note centrée |
| **ObligationBlock** | Carte avec bordure gauche colorée par catégorie (Impôts `primary`, CNPS `info`, CMU `#4E9273`, Autres `accent`), en-tête : titre + badge « Total dû : x F », sous-sections en sous-titres majuscules |
| **Charts** (recharts) | Barres `primary` ; série secondaire `#4E9273` ; alerte `accent` ; axes et grilles `border` ; valeurs en étiquette au-dessus des barres ; info-bulle avec montant formaté ; légende sous le graphique. Les graphiques du **rapport modernisé** (document PDF / Word) reprennent la même palette, déclarée une seule fois dans les paramètres du modèle, pour que l'aperçu à l'écran et le document se ressemblent |
| **EconomicMap** | Contour de la Côte d'Ivoire `primary-soft` et `primary`, bulles `primary` à 75 % d'opacité dont le rayon est proportionnel au CA, libellé de la DR sous la bulle ; tableau de détail sous la carte |
| **NotificationBell** | Compteur rond `accent`, panneau de 360 px avec les 20 dernières notifications |
| **RolePermissionsPage** (interface dédiée d'un rôle) | En-tête : nom du rôle, description, nombre d'utilisateurs, badge « personnalisé » ; corps : une carte repliable par module (titre, compteur « 7 / 12 droits », boutons « Tout accorder / Tout retirer ») contenant les droits en lignes (case à cocher, libellé 600, description `muted`, pastille « personnalisé » `info` si différent du réseau, cadenas 🔒 et info-bulle si verrouillé) ; colonne droite (sous le contenu sur mobile) : utilisateurs du rôle ; pied fixe : « Revenir aux droits par défaut » à gauche, « Enregistrer » à droite |
| **PermissionCloud** (habilitations par utilisateur) | Droits regroupés par module dans des cartes ; chaque droit = case à cocher + libellé ; pastille d'origine à droite (« profil » gris, « centre » `info`, « ajusté » `accent`) ; ligne verrouillée grisée avec 🔒 et info-bulle ; compteur « n ajustements » en tête ; lien « Revenir au profil » par case et global |
| **FneStatus** | Sur une facture : StatusBadge + numéro FNE en police à chasse fixe + vignette du QR de certification (48 px, agrandissable) ; tant que la facture n'est pas certifiée, boutons Télécharger / Envoyer désactivés avec info-bulle « Disponible après certification FNE » |
| **StepTimeline** (campagne de clôture) | Frise horizontale de 4 étapes (pièces, régularisation, finalisation, dépôt) : date sous chaque point, point plein `primary` = fait, cerclé `accent` = en cours, `danger` = en retard ; sur mobile, frise verticale |
| **DeadlineBanner** (rapport) | Ligne « Date limite : jj/mm · échéance réglementaire : jj/mm » ; neutre avant la date limite, orange après, rouge après l'échéance réglementaire |
| **SourceTag** | Petite étiquette à côté d'une valeur : « Compta Flow » (`info`, icône `RefreshCw`, date de synchronisation en info-bulle), « Import », « Saisie » (gris) ; une valeur « Compta Flow » est affichée en lecture seule avec cadenas |

Icônes lucide recommandées : `LayoutDashboard` (tableau de bord), `Users` (adhérents), `CalendarClock` (échéances), `FileSpreadsheet` (rapport), `MessageSquare` (préoccupations), `GraduationCap` (formations), `Megaphone` (communications), `Wallet` (cotisation), `Building2` (gestion interne, CGA), `Landmark` (bancabilité), `BarChart3` (statistiques), `Settings` (paramètres), `ShieldCheck` (vérification).

---

## 7. Écrans de référence (gabarits)

| Gabarit | Écrans | Disposition |
|---|---|---|
| **Tableau de bord** | `/cncga`, `/cga`, `/adherent/accueil` | Sélecteur de période · rangée de 4 KpiCard (2 × 2 sur tablette, 1 colonne sur mobile) · 2 cartes côte à côte (graphique / alertes ou échéances) |
| **Liste** | Adhérents, CGA, cotisations, préoccupations, formations | Titre + action principale · onglets éventuels · DataTable dans une carte `flush` |
| **Fiche** | Dossier adhérent, fiche CGA, rapport | En-tête de fiche (titre, numéro, badges, actions) · bandeaux · onglets · contenu en grille de 2 colonnes clé / valeur (étiquette `muted` à gauche, valeur 600 à droite, séparateur `border`) |
| **Assistant** | Inscription d'un adhérent, import, nouvel envoi | Stepper · un formulaire en 2 colonnes (1 sur mobile) · pied fixe : « ← Retour » / action principale |
| **Fil** | Préoccupation | En-tête (objet, catégorie, statut, délai écoulé) · ConversationThread · zone de réponse fixe en bas |
| **Rapport** | Rapport trimestriel | DeadlineBanner en tête · colonne gauche : 6 sections avec ProgressBar (sommaire collant) · colonne droite : section ouverte (états avec graphiques et SourceTag) · barre d'actions en bas (Prévisualiser, Générer, Transmettre) |
| **Rapport modernisé (document)** | PDF / Word | Page de garde à l'identité du CGA · sommaire · « L'essentiel du trimestre » (4 chiffres clés en cartes + synthèse) · une double page par thème (graphique en haut, tableau réglementaire en dessous, commentaire) · annexes par CDI · pied de page avec n° de version et date de génération |
| **Clôture** | `/cga/cloture` | StepTimeline en tête avec compteurs · filtres · DataTable des dossiers (une colonne par étape avec ✓ / date / retard) |
| **Public** | Connexion, activation, vérification | §5.3 |

Reprendre de la maquette, à l'identique dans l'esprit :
- les cartes d'indicateurs ;
- les blocs d'obligations à bordure colorée ;
- les badges de statut ;
- l'étiquette de plateforme « ⇄ » ;
- les barres de complétude du rapport ;
- la carte économique à bulles ;
- la page d'accueil de connexion avec un bandeau « République de Côte d'Ivoire · Dispositif CGA ».

---

## 8. Responsive

| Point de rupture | Comportement |
|---|---|
| < 768 px | Une colonne ; barre de navigation basse (adhérent) ou menu en tiroir (CGA, CNCGA) ; tableaux → listes de cartes ; modales plein écran ; actions principales en bouton pleine largeur en bas de l'écran |
| 768–1023 px | Menu latéral en icônes ; indicateurs 2 × 2 ; formulaires sur 1 colonne |
| ≥ 1024 px | Mise en page complète §5.1 |

Les espaces CGA et CNCGA sont **conçus pour l'ordinateur** mais restent utilisables sur tablette et téléphone pour la consultation et les actions simples (confirmer un paiement, répondre à une préoccupation).

---

## 9. Accessibilité

- Conformité visée : **WCAG 2.1 AA**.
- Navigation complète au clavier ; contour de focus visible de 2 px `primary` décalé de 2 px.
- Tous les champs ont un libellé associé ; les erreurs sont reliées au champ (`aria-describedby`) et annoncées.
- Les badges et icônes d'état ont un texte ; les graphiques ont un tableau de données équivalent ou un résumé textuel.
- Taille de texte minimale de 12 px ; l'interface reste utilisable avec un zoom de 200 %.
- Langue de la page `fr`.

---

## 10. Micro-interactions et retours

- Survol : assombrissement léger (boutons), fond `primary-soft` (lignes, éléments de menu). Transitions de 150 ms, sans rebond.
- Enregistrement automatique (rapport) : indicateur « Enregistrement… » puis « Enregistré à 14:32 » en note.
- Opérations longues : bouton en état de chargement, puis toast ; aucune barre de progression factice.
- Après une action réussie, l'élément concerné est brièvement surligné (fond `primary-soft`, 1,5 s).

---

## 11. Textes types

| Contexte | Texte |
|---|---|
| Bouton principal d'inscription | « Valider l'adhésion » |
| Succès d'adhésion | « Adhésion validée — n° 2026-DCK-0143 » |
| Blocage d'exclusivité | « Ce contribuable est adhérent d'un autre CGA. L'inscription sera possible dès que sa radiation y aura été validée. » |
| Demande de radiation | « La demande est enregistrée. Le dossier reste suivi jusqu'à la validation de la radiation. » |
| Validation de radiation (confirmation) | « La validation met fin au contrat CGA, ajoute l'adhérent à l'état des résiliations du trimestre et prépare le courrier de notification au CDI. Elle peut être annulée pendant 30 jours. » |
| Facture non certifiée | « Cette facture sera disponible après sa certification FNE. » |
| Bascule vers le portefeuille CGA | « Dossier intégré au portefeuille CGA — n° 2026-DCK-0151. Il figurera parmi les nouveaux adhérents du trimestre. » |
| Liste d'adhérents vide | « Aucun adhérent pour l'instant. Inscrivez votre premier adhérent ou importez votre portefeuille. » |
| Couverture des statistiques | « Sur la base de 18 CGA abonnés sur 111. » |
| Page de vérification valide | « Document authentique et valide » |
| Quota SMS épuisé | « Votre quota de SMS du mois est épuisé (200 / 200). Les messages restent envoyés dans la plateforme et par e-mail. » |
