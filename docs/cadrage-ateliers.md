# NormaPrep — Note de cadrage : les Ateliers

> Statut : **validé**, base du plan de tests puis du développement.
> Version 0.2, octobre 2026 (décisions du § 11 intégrées).

---

## 1. Objectif

Ajouter à NormaPrep une partie **pratique**, à côté des examens blancs, des
révisions et des flashcards : des **mises en situation** où l'apprenant agit
comme un Lead Implementer dans une entreprise fictive.

Les quiz vérifient que l'on **sait**. Les ateliers vérifient que l'on sait
**faire** : repérer ce qui ne tient pas dans un document, trier des problèmes,
préparer un livrable.

### Principe directeur : pratique, mais corrigée sans effort

Les essais précédents ont buté sur la lourdeur. La règle de ce module est
donc : **aucune correction de texte libre par la machine**. Tout ce qui est
noté l'est par comparaison avec une clé (cases cochées, lignes cliquées).
Le texte libre existe, mais il sert d'entraînement, et l'apprenant le
compare lui-même à une réponse modèle, à l'aide d'une grille.

### Ce qui nous distingue

Les plateformes étudiées (§ 10) génèrent leur contenu par IA sans relecture
humaine et le corrigent par IA. NormaPrep fait l'inverse : **contenu écrit et
relu, en français, centré sur le Lead Implementer, avec un contexte
français** (CNIL, ANSSI, NIS 2). C'est un argument à afficher.

---

## 2. Périmètre

### Inclus dans la première version

| Brique | Détail |
|---|---|
| Atelier | Une entreprise fil rouge (un scénario existant) et une suite ordonnée de tâches |
| Tâche « rédaction » | Situation, consigne, réponse libre facultative, questions fermées notées, réponse modèle, grille d'auto-évaluation |
| Tâche « écart » | Un ou deux documents en lignes numérotées ; l'apprenant clique les lignes non conformes ; indice puis correction ligne par ligne |
| Progression | Enregistrée par tâche ; un atelier est terminé quand toutes ses tâches le sont |
| Import | Fichiers JSON dans un sous-dossier `ateliers/` de la certification, rejouables, tout ou rien |
| Accès | Celui de la certification (bibliothèque), sauf les ateliers « découverte », ouverts à tout compte connecté |
| Prérequis | Une tâche peut conseiller d'en faire d'autres avant elle, sans rien bloquer |

### Reporté à plus tard

- Matrice des risques à manipuler (placer des risques sur une grille 5×5 et
  comparer à l'avis de l'expert) : troisième type de tâche, prévu par le
  modèle (§ 6.2), non développé.
- Correction du texte libre par IA.
- Intégration à l'écran Activité (courbe, calendrier de régularité).
- Écart du jour (un exercice court mis en avant chaque jour).
- Administration fine des ateliers dans le back-office : la première version
  passe **uniquement par l'import**, l'écran d'administration se limite à
  une liste en lecture seule (§ 8).
- Points, rangs, classement : volontairement exclus (l'écran Activité joue
  déjà ce rôle).

---

## 3. Acteurs et accès

| Acteur | Ce qu'il peut faire |
|---|---|
| Visiteur non connecté | Rien : l'espace apprenant demande de se connecter, comme aujourd'hui |
| Compte connecté sans la certification | Ouvrir et faire les ateliers **découverte** ; voir la liste des autres ateliers (titre, résumé) avec l'invitation à acquérir la certification, sans pouvoir ouvrir leurs tâches |
| Apprenant détenant la certification | Ouvrir tous les ateliers publiés de cette certification, enregistrer ses réponses, voir ses corrections |
| Administrateur | Importer, lister, voir les statistiques de passage |

Chaque atelier porte un attribut d'**accès** :

| Valeur | Qui peut ouvrir les tâches |
|---|---|
| `certification` (par défaut) | Les détenteurs d'un accès non expiré à la certification : `NPQ_Bibliotheque::a_acces()`, le même droit que pour les examens |
| `decouverte` | Tout compte connecté, qu'il détienne ou non la certification |

L'accès « découverte » ne crée **aucun droit** dans la bibliothèque : il
ouvre cet atelier-là, rien d'autre. Un compte reste nécessaire, pour
enregistrer la progression et pour que la découverte mène à l'inscription.
La vérification se fait **côté serveur** à chaque ouverture et à chaque
enregistrement, pas seulement en masquant des liens.

Les réponses d'un apprenant ne sont lues **que par lui**. L'administrateur
voit des compteurs (tâches terminées, score moyen), jamais les textes libres.

---

## 4. Parcours de l'apprenant

```
 Ateliers ──► Atelier (entreprise + tâches) ──► Tâche ──► Correction ──► Tâche suivante
 (liste)        progression : 3 / 8                          │
                                                             └── retour à l'atelier
```

1. **Liste des ateliers** — nouvelle entrée « Ateliers » dans la barre
   latérale, entre « Révisions » et « Flashcards ». Pour chaque atelier :
   titre, entreprise, nombre de tâches, progression.
2. **Page de l'atelier** — présentation de l'entreprise (le contexte du
   scénario) et liste ordonnée des tâches avec leur état : à faire, en cours,
   terminée, et le score des parties notées.
3. **Une tâche** — voir § 5. Les tâches s'ouvrent **dans n'importe quel
   ordre** : l'ordre est conseillé, pas imposé.

   L'ordre logique est tenu de deux façons :
   - la **position** des tâches dans l'atelier suit le déroulé d'un projet
     (contexte, puis risques, puis DdA…) ; c'est l'ordre d'affichage ;
   - une tâche peut déclarer des **prérequis** : les tâches dont elle
     reprend les résultats. Si l'apprenant ouvre une tâche dont un prérequis
     n'est pas terminé, un bandeau le conseille (« Cette tâche s'appuie sur
     *Définir le périmètre* : nous vous conseillons de la faire d'abord »),
     avec un lien. Il peut passer outre.

   Un prérequis est toujours une tâche **placée avant** dans le même
   atelier ; l'import refuse le contraire. Cela suffit à garantir qu'il n'y
   a jamais de boucle.
4. **Correction** — reste consultable ensuite ; l'apprenant peut **refaire**
   une tâche, le meilleur score est conservé.

---

## 5. Les types de tâche

Chaque tâche, quel que soit son type, commence par le même gabarit
d'écriture, repris des scénarios de Do GRC (§ 10) :

| Bloc | Rôle | Exemple |
|---|---|---|
| Situation | Ce qui se passe, avec des faits précis et chiffrés | « Lors de la revue des accès, 12 comptes d'anciens salariés sont encore actifs, dont 3 administrateurs. » |
| Enjeu | La pression du moment et les contraintes | « Le comité de direction se réunit dans 5 jours ; la DSI n'a qu'une personne disponible. » |
| Consigne | Ce que l'on attend de l'apprenant | « Classez ces constats et proposez une action corrective pour chacun. » |
| Rattachement | Clause ISO 27001 et domaine d'examen | Clause 10.2, domaine D5 |

### 5.1 Tâche « rédaction »

```
 Situation + consigne
        │
        ├── 1. Ma réponse (texte libre, facultatif, enregistré au fil de l'eau)
        │
        ├── 2. Questions fermées (QCM, 1 à 5) ──► notées sur 100
        │
        └── 3. Réponse modèle + grille d'auto-évaluation (« ai-je pensé à… ? »)
```

- **Texte libre** : facultatif, limité à 10 000 caractères, jamais noté.
- **Questions fermées** : même fonctionnement que les questions d'examen
  (choix unique ou multiple, explication). Score = part des questions
  justes, sur 100. Une tâche peut ne pas en avoir.
- **Réponse modèle et grille** : s'affichent **après validation** des
  questions fermées. Si la tâche n'en a pas, ou si l'apprenant veut les voir
  sans répondre, un bouton « Voir la réponse modèle » les affiche, après une
  confirmation (« Vous ne pourrez plus vous entraîner à l'aveugle sur cette
  tâche »).
- **Grille** : l'apprenant coche les critères qu'il a remplis. Les cases
  cochées sont enregistrées, mais **ne comptent pas dans le score** : c'est
  une auto-évaluation, pas une note.
- La tâche est **terminée** quand les questions fermées sont validées (ou
  quand la réponse modèle a été affichée, si la tâche n'a pas de questions).

### 5.2 Tâche « écart »

Inspirée de l'exercice CTRL+F de grcengineer.com et des environnements de lab
de Do GRC.

- Un ou deux **documents** de l'entreprise s'affichent en **lignes
  numérotées** : extrait de politique, ligne de DdA, registre des risques,
  compte rendu de revue de direction, preuve d'audit… Avec deux documents,
  ils s'affichent côte à côte (l'un sous l'autre sur mobile) : les meilleurs
  écarts naissent de la contradiction entre deux documents.
- L'apprenant **sélectionne les lignes** qu'il juge non conformes, puis
  valide. Aucune ligne n'est surlignée d'avance, aucun indice n'est donné
  avant le premier essai.
- **Premier essai** : on n'affiche **pas** la solution. On affiche le score
  et un retour qui dit **où regarder** sans donner la réponse : « Il vous
  manque 2 écarts, dont un dans le registre des risques », « 1 ligne
  sélectionnée est conforme ». Chaque écart peut porter un **indice** propre,
  affiché à ce moment.
- **Premier essai parfait** (tous les écarts, aucune fausse alerte) : la
  correction s'affiche directement, il n'y a rien à chercher de plus.
- **Deuxième essai**, ou bouton « Voir la correction » à tout moment :
  correction ligne par ligne. Chaque ligne affiche son explication, qu'elle
  soit un écart (pourquoi elle ne va pas, quelle exigence elle viole) ou
  non (pour les lignes piégeuses : pourquoi elle est en réalité conforme).
- **Score** sur 100 :

  ```
  score = max( 0, écarts trouvés − lignes conformes sélectionnées ) / nombre d'écarts × 100
  ```

  Une ligne conforme sélectionnée par erreur annule un écart trouvé : sans
  cette pénalité, tout sélectionner donnerait 100.
- La tâche est **terminée** dès que la correction a été affichée.

### 5.3 Score d'une tâche et d'un atelier

- Score d'une tâche = score de ses parties notées (QCM ou lignes).
  **Meilleur score conservé** quand on la refait.
- Une tâche sans partie notée n'a pas de score ; elle compte seulement dans
  la progression.
- Score d'un atelier = moyenne des scores de ses tâches notées terminées.
  Progression = tâches terminées / tâches publiées.

---

## 6. Modèle de données

### 6.1 Pourquoi des tables séparées des questions d'examen

Les questions d'atelier ressemblent aux questions d'examen, mais **ne doivent
jamais apparaître dans un examen ou une révision** : elles s'appuient sur la
situation de la tâche, que l'examen n'affiche pas. Or une dizaine de requêtes
(composeur d'examen, révisions, compteurs de la boutique) piochent dans la
table `question` par certification ou par domaine. Ajouter une colonne et un
filtre à chacune serait une source d'oubli. Les questions d'atelier ont donc
leurs propres tables.

L'**entreprise**, elle, est un **scénario existant** : on ne duplique pas le
contexte. Un atelier pointe vers un scénario, et ce scénario peut aussi servir
aux examens.

### 6.2 Tables

```
 certification 1──n atelier n──1 scenario
                      │
                      1
                      │
                      n
               atelier_tache ──1──n atelier_question 1──n atelier_option
                 │    │
                 │    └──1──n atelier_critere          (grille, tâche « rédaction »)
                 │
                 └──1──n atelier_document 1──n atelier_ligne   (tâche « écart »)

 utilisateur 1──n atelier_progression n──1 atelier_tache
```

| Table | Colonnes principales | Remarques |
|---|---|---|
| `atelier` | id, certification_id, scenario_id, ref_externe, titre, resume, acces, position, statut | ref : `LI27001-AT01` ; acces : `certification` ou `decouverte` |
| `atelier_tache` | id, atelier_id, ref_externe, position, type, titre, situation, enjeu, consigne, reponse_modele, clause, domaine, prerequis, statut | type : `redaction`, `ecart` (`matrice` réservé). prerequis : liste JSON de références de tâches. ref : `LI27001-AT01-T03` |
| `atelier_question` | id, tache_id, ref_externe, position, enonce, multi_reponses, explication | ref : `…-T03-Q02` |
| `atelier_option` | id, question_id, position, texte, correcte | |
| `atelier_critere` | id, tache_id, position, texte | grille d'auto-évaluation |
| `atelier_document` | id, tache_id, ref_externe, position, titre, nature | nature : politique, registre, DdA, compte rendu, preuve… ref : `…-T05-D01` |
| `atelier_ligne` | id, document_id, numero, texte, est_ecart, explication, indice | `indice` seulement pour les écarts |
| `atelier_progression` | id, utilisateur_id, tache_id, statut, texte_libre, reponses, criteres, essais, score, meilleur_score, modele_vu, date_debut, date_maj, date_fin | une ligne par apprenant et par tâche (clé unique) |

Dans `atelier_progression` :
- `statut` : `en_cours` ou `terminee` ;
- `reponses` (JSON) : dernières options cochées (rédaction) ou lignes
  sélectionnées (écart), **désignées par leurs références de fichier**
  (`Q02` → `B`, `D01` → ligne 5), jamais par des id internes : un réimport
  ne les rend pas illisibles ;
- `criteres` (JSON) : critères de la grille cochés ;
- `modele_vu` : la réponse modèle ou la correction a été affichée avant la
  fin (sert à l'avertissement du § 5.1 et aux statistiques).

### 6.3 Statuts et suppressions

- `statut` = `publie` ou `brouillon` sur l'atelier, `publie`, `brouillon`
  ou `retiree` sur la tâche. Seul le publié est visible des apprenants.
- Un atelier dont le scénario est en brouillon ou absent n'est **pas
  visible**.
- Suppression d'un compte apprenant : ses lignes de progression sont
  supprimées avec lui (texte libre compris).
- **Une tâche retirée du fichier d'import n'est pas supprimée** : elle passe
  au statut `retiree`. Elle disparaît pour les apprenants et ne compte plus
  dans la progression ni dans le score de l'atelier, mais la progression de
  chacun est **conservée**. Si la tâche revient dans le fichier (même
  référence), elle est republiée et retrouve sa progression. Le compte rendu
  d'import l'annonce (« 1 tâche retirée, 14 progressions conservées »).
  Raison : une erreur dans un fichier ne doit jamais effacer le travail des
  apprenants ; c'est la règle déjà suivie par le reste du plugin.
- À l'intérieur d'une tâche, le contenu (questions, options, documents,
  lignes) est **remplacé** à chaque import : c'est du contenu, pas une
  donnée d'apprenant. Les scores déjà obtenus ne sont **pas recalculés**
  après une correction de contenu : ils restent ceux de la version passée.
- Un fichier d'atelier qui disparaît du dossier ne déclenche rien : pour
  retirer un atelier, on le passe en `draft` dans son fichier.

---

## 7. Format d'import

### 7.1 Emplacement

```
 data/
   LI27001/
     _certification.json
     SC01_….json            <- scénarios (inchangés)
     ateliers/
       AT01_Rhodanis.json   <- un atelier et toutes ses tâches
       AT02_….json
```

Le sous-dossier `ateliers/` est **ignoré** par l'import des scénarios (il ne
lit que les `.json` du dossier lui-même) : les deux imports ne se gênent pas.

### 7.2 Contenu d'un fichier

```json
{
  "schema_version": "1.0",
  "atelier": {
    "id": "AT01",
    "scenario": "SC01",
    "title": "Remettre le SMSI de Rhodanis sur les rails",
    "summary": "…",
    "access": "certification",
    "status": "published"
  },
  "tasks": [
    {
      "id": "T01",
      "type": "redaction",
      "title": "Définir le périmètre du SMSI",
      "situation": "…", "stakes": "…", "instruction": "…",
      "clause": "4.3", "domain": "D2",
      "model_answer": "…",
      "criteria": ["J'ai exclu explicitement…", "J'ai justifié…"],
      "questions": [
        { "id": "Q01", "question": "…", "options": [ { "id": "A", "text": "…" } ],
          "answer": ["B"], "explanation": "…" }
      ]
    },
    {
      "id": "T02",
      "type": "ecart",
      "title": "Relire la Déclaration d'applicabilité",
      "situation": "…", "stakes": "…", "instruction": "…",
      "clause": "6.1.3", "domain": "D3",
      "requires": ["T01"],
      "documents": [
        { "id": "D01", "title": "Extrait de la DdA", "nature": "dda",
          "lines": [
            { "text": "…", "gap": false, "explanation": "…" },
            { "text": "…", "gap": true,  "explanation": "…", "hint": "…" }
          ] }
      ]
    }
  ]
}
```

Les clés suivent la convention anglaise des fichiers de scénario existants.
Correspondances : `access` → `acces` (`certification` ou `discovery` →
`decouverte`), `requires` → `prerequis`, `status` → `statut`
(`published` → `publie`, `draft` → `brouillon`).

Une tâche peut aussi porter `"status": "draft"` pour être préparée sans
être visible.

### 7.3 Contrôles à l'import (fichier rejeté en entier si l'un échoue)

- La certification du dossier existe ; le scénario référencé existe dans
  cette certification.
- Identifiants uniques dans le fichier (tâches, questions, documents).
- Type de tâche connu (`redaction` ou `ecart`).
- Accès connu (`certification` ou `discovery`) ; absent = `certification`.
- Chaque prérequis désigne une tâche **placée avant** dans le même fichier.
- Domaine connu de la certification.
- Tâche « rédaction » : réponse modèle non vide ; chaque question a au
  moins deux options et au moins une bonne réponse ; `answer` ne cite que
  des options existantes.
- Tâche « écart » : un ou deux documents ; au moins un écart et au moins
  une ligne conforme au total ; chaque ligne a une explication.
- L'import est **rejouable** : réimporter le même fichier ne crée rien de
  plus et ne touche pas à la progression des tâches conservées.

---

## 8. Écrans

| Écran | Côté | Contenu |
|---|---|---|
| Ateliers | Espace apprenant | Liste par certification (sélecteur de certification existant), progression par atelier |
| Atelier | Espace apprenant | Contexte de l'entreprise, tâches ordonnées avec état et score |
| Tâche « rédaction » | Espace apprenant | Situation, enjeu, consigne ; zone de texte (enregistrement automatique) ; QCM ; réponse modèle et grille |
| Tâche « écart » | Espace apprenant | Documents en lignes cliquables ; validation ; retour « où regarder » ; correction ligne par ligne |
| Ateliers (admin) | Administration | Liste en lecture seule : atelier, nombre de tâches, apprenants ayant commencé, ayant terminé, score moyen |
| Import | Administration | La page d'import existante affiche aussi les fichiers `ateliers/` et leur compte rendu |

Sur mobile, les tâches restent utilisables (contrairement à l'examen, qui est
déconseillé sur petit écran) : ce sont des exercices courts.

---

## 9. Découpage en lots

| Lot | Contenu | Livrable vérifiable |
|---|---|---|
| 1 | Tables, import des ateliers (contrôles, rejouabilité, suppressions), liste admin | L'import d'un fichier d'exemple crée les lignes attendues ; un fichier fautif est rejeté sans rien écrire |
| 2 | Espace apprenant : liste, page d'atelier, tâche « rédaction », progression | Un apprenant fait une tâche de bout en bout ; un autre ne voit pas ses réponses ; un non-détenteur ne peut rien ouvrir |
| 3 | Tâche « écart » : documents, sélection, score, indice, correction | Le score suit la formule du § 5.2 sur des cas limites |
| 4 | Premier atelier réel (contenu) | Un atelier LI27001 complet, relu |

Chaque lot a son script de tests de bout en bout, sur le modèle de NormaPrep
Diagnostic (`wp eval-file tests/test-ateliers-lotN.php`), à exécuter sur un
WordPress **de test**. Le [plan de tests](ateliers-plan-de-tests.md) est
écrit avant le développement ; son jeu d'essai est dans
`tests/fixtures/ateliers/`.

---

## 10. Sources d'inspiration

Pages consultées en octobre 2026. On en reprend des **formats**, jamais des
textes : leurs contenus sont protégés.

| Plateforme | Ce qu'on en retient |
|---|---|
| grcengineeringlab.com | Leçon en trois temps « lire, décider, construire » ; réponse structurée vérifiée point par point ; indices et solution révélée seulement après plusieurs essais |
| dogrc.com — environnements de lab | Documents d'une entreprise fictive, sans indice ; écarts nés du croisement de deux documents ; retour qui dit ce qu'on n'a pas exploité sans donner la réponse ; meilleur score conservé |
| dogrc.com — parcours de scénarios | Une entreprise fil rouge sur dix tâches ; gabarit situation → enjeu → faits chiffrés → personnages → consigne |
| grcengineer.com — CTRL+F | Jeu quotidien où les lignes fausses s'expliquent d'elles-mêmes |
| dogrc.com — Arcade | Matrice des risques à manipuler (reportée, § 2) |
| grc.engineering, grcengclub.com | Ressources et communauté ; peu transposables au module, utiles pour une future page « Ressources » |

Ce qu'on **écarte** volontairement : la correction par IA (coût, fiabilité,
contenu non relu), les points d'expérience et les classements, les labs
techniques (code, cloud), hors du champ du Lead Implementer.

---

## 11. Décisions

| # | Question | Décision |
|---|---|---|
| 1 | Atelier ouvert sans la certification ? | **Oui**, attribut d'accès `decouverte`, compte connecté requis (§ 3) |
| 2 | Nom du module | **Ateliers** |
| 3 | Ordre des tâches | **Libre**, avec un ordre d'affichage logique et des prérequis conseillés, non bloquants (§ 4) |
| 4 | Tâche retirée du fichier | **Passée en `retiree`**, progression conservée et restaurée si la tâche revient (§ 6.3) |
