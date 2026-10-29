# NormaPrep — Ateliers : plan de tests

> Statut : **proposition**, écrit **avant** le développement.
> Version 0.1, octobre 2026. Référence : [note de cadrage](cadrage-ateliers.md) v0.2.

---

## 1. Objet

Ce plan fixe, avant d'écrire le code, **ce que les Ateliers doivent faire**
et **comment on le vérifie**. Le développement d'un lot est terminé quand
tous ses cas passent.

Chaque cas a :
- un **identifiant** (`I-03`, `R-12`…), repris dans la sortie du script ;
- un **mode** : **A** = automatisé (script `wp eval-file`), **M** = recette
  manuelle dans le navigateur (affichage, ergonomie, mobile) ;
- un **résultat attendu** chiffré quand c'est possible.

Les cas automatisés sont écrits sur le modèle de NormaPrep Diagnostic :
un script par lot, qui affiche `✔` ou `✘` par vérification et un total.

```
wp eval-file tests/test-ateliers-lot1.php --user=<administrateur>
```

> À exécuter sur un WordPress **de test** uniquement : les scripts créent des
> comptes, une certification `TEST27001` et importent des ateliers.

---

## 2. Environnement de test

Le script prépare lui-même, à chaque lancement, et nettoie à la fin :

| Élément | Valeur | Rôle |
|---|---|---|
| Certification | `TEST27001`, domaines `D1` à `D5` | Isole les tests du contenu réel |
| Scénario | `TEST27001-SC01` « Rhodanis », publié, avec 3 questions d'examen | L'entreprise des ateliers ; ses questions servent aux tests de non-fuite |
| Compte **Claire** | détient `TEST27001` sans échéance | Apprenante avec accès |
| Compte **Bruno** | détient `TEST27001` sans échéance | Second apprenant, pour le cloisonnement |
| Compte **Sam** | ne détient pas `TEST27001` | Compte sans la certification |
| Compte **Elsa** | détient `TEST27001`, échéance passée (hier) | Accès expiré |
| Visiteur | non connecté | |

Les fichiers d'atelier viennent de `tests/fixtures/ateliers/`. Pour les cas
d'erreur, le script en fait une **copie de travail** dans le dossier
temporaire et la modifie (comme `npd_json_modifier` dans Diagnostic) :
l'original n'est jamais touché.

---

## 3. Jeu d'essai

### 3.1 `AT01_Rhodanis.json` — accès `certification`

| Tâche | Type | Statut | Contenu noté | Prérequis |
|---|---|---|---|---|
| T01 Définir le périmètre | rédaction | publiée | Q01 (choix unique, réponse B), Q02 (choix multiple, réponses A, B, C) ; 3 critères | — |
| T02 Engagement de la direction | rédaction | publiée | aucune question ; 3 critères | T01 |
| T03 Relire la DdA | écart | publiée | D01 (6 lignes), D02 (5 lignes) ; écarts : **D01-3, D01-5, D02-5** | T01 |
| T04 Préparer l'audit interne | rédaction | **brouillon** | — | — |

### 3.2 `AT02_Decouverte.json` — accès `discovery`

| Tâche | Type | Contenu noté |
|---|---|---|
| T01 Trouver l'écart dans le compte rendu | écart | D01 (4 lignes) ; écart : **D01-3** |

### 3.3 Totaux attendus après import des deux fichiers

| Table | Lignes |
|---|---|
| `atelier` | 2 |
| `atelier_tache` | 5 (dont 1 brouillon) |
| `atelier_question` | 2 |
| `atelier_option` | 8 |
| `atelier_critere` | 6 |
| `atelier_document` | 3 |
| `atelier_ligne` | 15 (dont 4 écarts) |

> Ces chiffres dépendent des fichiers : toute modification d'une fixture doit
> être reportée ici et dans les scripts.

---

## 4. Points d'entrée attendus du code

Pour être testable sans navigateur, le code expose des fonctions appelables
depuis le script. Les noms ci-dessous sont **indicatifs** ; ce qui compte est
qu'il existe un point d'entrée pour chaque action.

| Action | Point d'entrée indicatif | Renvoie |
|---|---|---|
| Importer un dossier d'ateliers | `NPQ_Ateliers_Import::importer_dossier( $dossier, $certification_id )` | compte rendu : créés, mis à jour, retirés, erreurs par fichier |
| Ateliers visibles par un compte | `NPQ_Ateliers::liste( $wp_user_id, $certification_id )` | ateliers avec `ouvrable` (oui/non), progression, score |
| Ouvrir une tâche | `NPQ_Ateliers::tache( $wp_user_id, $tache_id )` | la tâche **sans** les éléments de correction, ou une erreur d'accès |
| Enregistrer le texte libre | `NPQ_Ateliers::enregistrer_texte( $wp_user_id, $tache_id, $texte )` | ok / erreur |
| Valider les questions | `NPQ_Ateliers::valider_questions( $wp_user_id, $tache_id, $reponses )` | score, correction |
| Afficher la réponse modèle | `NPQ_Ateliers::voir_modele( $wp_user_id, $tache_id )` | réponse modèle, grille |
| Cocher la grille | `NPQ_Ateliers::enregistrer_criteres( $wp_user_id, $tache_id, $criteres )` | ok / erreur |
| Valider une sélection de lignes | `NPQ_Ateliers::valider_lignes( $wp_user_id, $tache_id, $lignes )` | score, retour (essai 1) ou correction (essai 2) |
| Voir la correction d'un écart | `NPQ_Ateliers::voir_correction( $wp_user_id, $tache_id )` | correction ligne par ligne |

Le **contrôle d'accès est fait dans ces fonctions**, pas seulement dans les
écrans : c'est ce qui permet de le tester, et ce qui empêche de le
contourner en appelant directement l'action (requête AJAX forgée).

---

## 5. Lot 1 — Tables et import

### 5.1 Installation

| Id | Mode | Cas | Attendu |
|---|---|---|---|
| I-01 | A | Activation du plugin | Les 8 tables `atelier*` existent |
| I-02 | A | Réactivation | Aucune erreur, aucune table recréée ni vidée |

### 5.2 Import nominal

| Id | Mode | Cas | Attendu |
|---|---|---|---|
| I-10 | A | Import du dossier de fixtures | Totaux du § 3.3 exacts ; compte rendu sans erreur |
| I-11 | A | Références externes | `TEST27001-AT01`, `TEST27001-AT01-T03`, `TEST27001-AT01-T01-Q02`, `TEST27001-AT01-T03-D02` existent |
| I-12 | A | Correspondances | AT01 `acces` = `certification`, AT02 `acces` = `decouverte` ; T04 `statut` = `brouillon` ; T03 `prerequis` = `["T01"]` ; Q02 `multi_reponses` = 1 |
| I-13 | A | Ordre | `position` des tâches de AT01 = 1, 2, 3, 4 ; lignes de D01 numérotées 1 à 6 |
| I-14 | A | Rattachement | Les deux ateliers pointent vers le scénario `TEST27001-SC01` |
| I-15 | A | Accès absent du fichier | Un atelier sans clé `access` est importé avec `acces` = `certification` |

### 5.3 Rejouabilité

| Id | Mode | Cas | Attendu |
|---|---|---|---|
| I-20 | A | Réimport du même dossier | Totaux inchangés ; **id** des ateliers et des tâches inchangés |
| I-21 | A | Modification d'un texte (titre de T01) puis réimport | Titre mis à jour, id de T01 inchangé |
| I-22 | A | Réimport alors que Claire a une progression sur T01 et T03 | Progressions intactes (statut, score, texte libre) |
| I-23 | A | Option supprimée d'une question puis réimport | La question n'a plus que 3 options ; la progression de Claire reste lisible (réponses désignées par références, § 6.2 du cadrage) |

### 5.4 Tâche retirée du fichier

| Id | Mode | Cas | Attendu |
|---|---|---|---|
| I-30 | A | T02 retirée de la copie de AT01 (Claire l'a terminée), réimport | T02 `statut` = `retiree` ; ligne de progression de Claire **conservée** ; compte rendu : « 1 tâche retirée, 1 progression conservée » |
| I-31 | A | Après I-30, liste des tâches pour Claire | T02 absente ; progression de AT01 calculée sur 2 tâches (T01, T03) |
| I-32 | A | Après I-30, ouverture directe de T02 par son id | Refusée (tâche introuvable) |
| I-33 | A | T02 remise dans le fichier, réimport | T02 `statut` = `publie`, même id ; Claire la retrouve terminée |
| I-34 | A | Fichier AT02 supprimé du dossier, réimport | AT02 inchangé en base (aucune suppression) |
| I-35 | A | AT01 passé en `draft` dans son fichier, réimport | AT01 `statut` = `brouillon` ; invisible pour Claire ; progressions conservées |

### 5.5 Rejets (le fichier fautif n'écrit **rien**)

Pour chaque cas : copie de AT01 modifiée, import, puis vérification que le
compte rendu cite le fichier et la raison, et que **les 7 tables de contenu
sont identiques à avant l'import** (aucune écriture partielle). AT02, valide,
est importé normalement dans le même passage.

| Id | Modification de la copie | Raison attendue dans le compte rendu |
|---|---|---|
| I-40 | JSON mal formé (virgule en trop) | fichier illisible |
| I-41 | `scenario` = `SC99` | scénario inconnu |
| I-42 | Deux tâches `T01` | identifiant en double |
| I-43 | Deux questions `Q01` dans T01 | identifiant en double |
| I-44 | `type` = `quiz` | type de tâche inconnu |
| I-45 | `access` = `public` | accès inconnu |
| I-46 | `domain` = `D9` | domaine inconnu |
| I-47 | `model_answer` vide dans T02 | réponse modèle manquante |
| I-48 | Q01 avec une seule option | au moins deux options |
| I-49 | Q01 avec `answer` = `[]` | aucune bonne réponse |
| I-50 | Q01 avec `answer` = `["E"]` | réponse qui ne cite pas une option existante |
| I-51 | T03 sans document | un ou deux documents |
| I-52 | T03 avec trois documents | un ou deux documents |
| I-53 | T03 sans aucune ligne `gap: true` | au moins un écart |
| I-54 | T03 avec toutes ses lignes `gap: true` | au moins une ligne conforme |
| I-55 | Une ligne de T03 sans `explanation` | explication manquante |
| I-56 | T01 avec `requires` = `["T03"]` | prérequis placé après la tâche |
| I-57 | T03 avec `requires` = `["T09"]` | prérequis inconnu |
| I-58 | T02 avec `requires` = `["T02"]` | prérequis placé après la tâche (une tâche n'est pas son propre prérequis) |

### 5.6 Non-régression

| Id | Mode | Cas | Attendu |
|---|---|---|---|
| I-60 | A | Import des scénarios avec un dossier `ateliers/` présent dans la certification | Les fichiers d'atelier ne sont pas lus comme des scénarios ; aucun scénario créé ni erreur |
| I-61 | A | Composer un examen et une révision sur `TEST27001` après import des ateliers | Les questions tirées sont uniquement les 3 questions du scénario ; aucune question d'atelier |
| I-62 | A | Compteurs de questions de `TEST27001` (boutique, révisions) | Identiques avant et après l'import des ateliers |
| I-63 | M | Page d'import de l'administration | Les fichiers `ateliers/` apparaissent dans l'inventaire avec leur compte rendu |

---

## 6. Lot 2 — Espace apprenant et tâche « rédaction »

### 6.1 Accès (vérifié côté serveur)

| Id | Compte | Action | Attendu |
|---|---|---|---|
| R-01 | Visiteur | Page Ateliers | Renvoyé vers la connexion, comme les autres pages de l'espace |
| R-02 | Sam | Liste des ateliers de `TEST27001` | AT01 et AT02 listés ; AT02 `ouvrable` ; AT01 non `ouvrable`, avec l'invitation à acquérir la certification |
| R-03 | Sam | Ouvrir AT02-T01 | Autorisé |
| R-04 | Sam | Ouvrir AT01-T01 | Refusé |
| R-05 | Sam | Valider les questions de AT01-T01 en appelant directement l'action | Refusé ; aucune progression créée |
| R-06 | Elsa (accès expiré) | Ouvrir AT01-T01 | Refusé |
| R-07 | Elsa | Ouvrir AT02-T01 | Autorisé (découverte) |
| R-08 | Claire | Ouvrir AT01-T01 et AT02-T01 | Autorisé |
| R-09 | Claire | Ouvrir T04 (brouillon) par son id | Refusé ; T04 absente de la liste |
| R-10 | Claire | Scénario `SC01` passé en brouillon | AT01 et AT02 absents de la liste ; ouverture refusée |
| R-11 | Sam | Après R-03, bibliothèque de Sam | Inchangée : la découverte ne crée **aucun** droit sur `TEST27001` |
| R-12 | Claire | Requête sans jeton de sécurité (nonce) valide | Refusée |

### 6.2 Tâche « rédaction » : texte libre

| Id | Mode | Cas | Attendu |
|---|---|---|---|
| R-20 | A | Claire enregistre un texte dans T01 | Progression créée, `statut` = `en_cours`, texte relu à l'identique |
| R-21 | A | Texte de 10 001 caractères | Refusé avec un message (jamais tronqué en silence) ; le texte précédent reste enregistré |
| R-22 | A | Texte contenant `<script>alert(1)</script>` | Enregistré, mais réaffiché **échappé** : aucun script exécuté |
| R-23 | A | Bruno ouvre T01 | Ne voit pas le texte de Claire ; sa zone est vide |
| R-24 | A | Bruno appelle l'action d'enregistrement avec la tâche de Claire | N'écrit que dans **sa** propre progression |

### 6.3 Tâche « rédaction » : questions fermées

Réponses de référence : Q01 = B ; Q02 = A, B, C. Une question est juste
seulement si les options cochées sont **exactement** les bonnes.

| Id | Réponses envoyées | Score attendu | Statut |
|---|---|---|---|
| R-30 | Q01 = B ; Q02 = A, B, C | 100 | terminée |
| R-31 | Q01 = B ; Q02 = A, B | 50 | terminée |
| R-32 | Q01 = A ; Q02 = A, B, C, D | 0 | terminée |
| R-33 | Q01 = B ; Q02 sans réponse | Refusé : toutes les questions doivent avoir une réponse ; aucune progression modifiée |
| R-34 | Q01 = B, C (deux options sur une question à choix unique) | Refusé |
| R-35 | Q01 = `Z` (option inexistante) | Refusé |

| Id | Mode | Cas | Attendu |
|---|---|---|---|
| R-36 | A | Avant validation, contenu renvoyé par l'ouverture de T01 | Ne contient **ni** les bonnes réponses, **ni** les explications, **ni** la réponse modèle, **ni** la grille |
| R-37 | A | Après validation | Correction de chaque question (bonnes réponses, explication), réponse modèle et grille disponibles |
| R-38 | A | Claire refait T01 : 100, puis 50 | `score` = 50 (dernier), `meilleur_score` = 100 ; l'atelier utilise 100 |

### 6.4 Réponse modèle et grille

| Id | Mode | Cas | Attendu |
|---|---|---|---|
| R-40 | A | T02 (sans question) : Claire demande la réponse modèle | Réponse modèle et grille renvoyées ; `modele_vu` = 1 ; T02 `terminee`, sans score |
| R-41 | A | T01 : Claire demande la réponse modèle **avant** de valider | Renvoyée ; `modele_vu` = 1 ; T01 reste `en_cours` (elle a des questions) |
| R-42 | A | Après R-41, Claire valide T01 | Score calculé normalement ; `modele_vu` reste 1 |
| R-43 | A | Claire coche les critères 1 et 3 de T01 | Enregistrés ; relus à l'identique ; score de T01 inchangé |
| R-44 | A | Claire coche un critère inexistant (n° 7) | Refusé |
| R-45 | M | Bouton « Voir la réponse modèle » avant d'avoir répondu | Demande de confirmation affichée |

### 6.5 Prérequis et progression

| Id | Mode | Cas | Attendu |
|---|---|---|---|
| R-50 | A | Bruno (rien fait) ouvre T03 | Autorisé ; l'ouverture signale le prérequis non terminé : T01 |
| R-51 | A | Bruno termine T01, rouvre T03 | Plus de prérequis signalé |
| R-52 | A | Bruno ouvre T01 (sans prérequis) | Aucun prérequis signalé |
| R-53 | A | Progression de AT01 pour Claire après T01 terminée | 1 / 3 (T04 en brouillon ne compte pas) |
| R-54 | A | Claire : T01 = 100, T02 terminée (sans score), T03 = 67 | Progression 3 / 3 ; score de l'atelier = 84 (moyenne de 100 et 67, arrondie) ; T02 n'entre pas dans la moyenne |
| R-55 | A | Liste des ateliers pour Bruno | Ses propres chiffres, jamais ceux de Claire |

### 6.6 Suppression de compte

| Id | Mode | Cas | Attendu |
|---|---|---|---|
| R-60 | A | Suppression du compte de Bruno | Toutes ses lignes `atelier_progression` supprimées ; celles de Claire intactes |

### 6.7 Recette manuelle

| Id | Cas | Attendu |
|---|---|---|
| R-70 | Entrée « Ateliers » dans la barre latérale | Entre « Révisions » et « Flashcards », active sur les pages d'atelier |
| R-71 | Sélecteur de certification | Le même que sur Révisions et Flashcards |
| R-72 | Texte libre | Enregistrement automatique visible (« Enregistré ») ; rien de perdu au rechargement |
| R-73 | Bandeau de prérequis | Lisible, avec un lien vers la tâche conseillée ; n'empêche rien |
| R-74 | Mobile (largeur 375 px) | Tâche utilisable sans défilement horizontal |
| R-75 | Clavier seul | Questions, grille et boutons utilisables sans souris |

---

## 7. Lot 3 — Tâche « écart »

### 7.1 Score

Écarts de AT01-T03 : D01-3, D01-5, D02-5 (3 écarts, 8 lignes conformes).
Formule : `max( 0, écarts trouvés − lignes conformes sélectionnées ) / 3 × 100`,
arrondie à l'entier le plus proche.

| Id | Lignes sélectionnées | Trouvés | Conformes sélectionnées | Score |
|---|---|---|---|---|
| E-01 | D01-3, D01-5, D02-5 | 3 | 0 | 100 |
| E-02 | D01-3, D01-5 | 2 | 0 | 67 |
| E-03 | D02-5 | 1 | 0 | 33 |
| E-04 | D01-3, D01-5, D02-5, D01-4 | 3 | 1 | 67 |
| E-05 | D01-3, D02-4 | 1 | 1 | 0 |
| E-06 | D01-4 | 0 | 1 | 0 (jamais négatif) |
| E-07 | aucune | 0 | 0 | 0 |
| E-08 | les 11 lignes | 3 | 8 | 0 (tout sélectionner ne rapporte rien) |

### 7.2 Déroulé des essais

| Id | Mode | Cas | Attendu |
|---|---|---|---|
| E-10 | A | Contenu renvoyé par l'ouverture de T03 | Les lignes avec leur texte et leur numéro ; **aucun** `est_ecart`, explication ni indice |
| E-11 | A | Premier essai de Claire avec la sélection E-02 | Score 67 ; retour : « 1 écart manquant dans *Extrait du registre des risques* », « 0 ligne conforme sélectionnée » ; indice de D02-5 affiché ; **aucune** explication, **aucun** numéro de ligne d'écart révélé ; `statut` = `en_cours` |
| E-12 | A | Premier essai avec la sélection E-04 | Retour : « 1 ligne sélectionnée est conforme » dans *Extrait de la DdA* (sans dire laquelle) |
| E-13 | A | Deuxième essai | Score calculé ; correction ligne par ligne (les 11 lignes, avec écart oui/non et explication) ; `statut` = `terminee` ; `essais` = 2 |
| E-14 | A | Bruno demande la correction avant tout essai | Correction renvoyée ; `modele_vu` = 1 ; `statut` = `terminee`, score 0 |
| E-15 | A | Troisième essai après la correction | Accepté (refaire est permis) ; `meilleur_score` conservé |
| E-16 | A | Sélection avec un numéro de ligne inexistant (D01-9) ou un document inconnu (D07) | Refusé ; aucune progression modifiée |
| E-17 | A | Sélection avec la même ligne en double | Comptée une seule fois |
| E-18 | A | Sam fait AT02-T01, sélection D01-3 au premier essai (parfait) | Score 100 ; correction affichée directement ; `statut` = `terminee` ; `essais` = 1 |

### 7.3 Recette manuelle

| Id | Cas | Attendu |
|---|---|---|
| E-20 | Deux documents | Côte à côte sur grand écran, l'un sous l'autre sur mobile |
| E-21 | Sélection d'une ligne | Clic ou clavier (Entrée, Espace) ; état sélectionné visible autrement que par la couleur |
| E-22 | Avant correction | Aucune ligne mise en évidence ; le code source de la page ne contient pas les explications |
| E-23 | Correction | Distinction claire : écart trouvé, écart manqué, fausse alerte, ligne conforme |

---

## 8. Lot 4 — Premier atelier réel

| Id | Mode | Cas | Attendu |
|---|---|---|---|
| C-01 | A | Import du fichier réel dans `data/LI27001/ateliers/` | Aucun rejet |
| C-02 | M | Relecture du contenu | Chaque explication cite la bonne clause de l'ISO 27001:2022 ; aucun texte repris d'une autre plateforme |
| C-03 | M | Faisabilité | Une personne extérieure fait l'atelier sans aide ; chaque écart est trouvable à partir des seuls documents |
| C-04 | M | Durée | Chaque tâche prend entre 10 et 30 minutes |

---

## 9. Ce que ce plan ne couvre pas

- La matrice des risques, les statistiques de l'écran Activité et l'écart du
  jour : reportés (cadrage, § 2).
- La performance avec beaucoup d'apprenants : le volume attendu (quelques
  centaines de comptes) ne le justifie pas à ce stade.
