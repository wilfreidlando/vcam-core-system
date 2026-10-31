---
numero: F18
titre: Extraire les données d'un projet
situation: Sortir les données
famille: piloter
famille_titre: Extraire et piloter
profil: Opérateur
temps: 10 minutes
---

## Ce qu’il faut savoir avant

- **L’export prend tout ou rien.** L’archive contient tout ce que le projet a
  produit : ses clients, ses offres, ses abonnements, ses factures, ses
  paiements, ses notifications, son usage, ses portefeuilles et ses écritures,
  plus la balance. Il n’existe pas d’export partiel, et un client que vous avez
  effacé n’y réapparaît pas.
- **La demande part tout de suite, le fichier non.** L’archive est préparée en
  tâche de fond : comptez quelques minutes. Vous pouvez fermer la page et
  revenir — la demande reste dans la liste.
- **Le lien de téléchargement vit quinze minutes, et ne sert qu’une fois.**
  S’il expire, ou s’il a déjà servi, cliquez de nouveau sur **« Télécharger »** :
  un lien neuf est fabriqué à ce moment-là.
- **Le fichier est effacé au bout de sept jours.** Ensuite, la ligne reste dans
  la liste : c’est la trace d’une demande, plus une archive à récupérer.
- **Ce que vous sortez est confidentiel, et votre sortie est tracée.** La copie
  conservée par le Core est chiffrée ; celle que vous téléchargez est un
  fichier ordinaire, à ouvrir et à traiter comme un document sensible. Chaque
  téléchargement est inscrit au journal d’audit.

## Les étapes

### 1. Ouvrir la page du projet

Dans la barre latérale, **« Projets »** mène à la liste des projets. Repérez le
projet qui vous intéresse et cliquez sur **« Voir → »**.

→ **Ce qui se passe** : la page du projet s’ouvre : son nom, son statut, et une
liste de liens vers les écrans du projet.

### 2. Ouvrir « Export des données »

Dans cette liste de liens, cliquez sur **« Export des données »**.

→ **Ce qui se passe** : la page affiche deux blocs : **« Demander un export »**,
en haut, et **« Exports précédents »**, en dessous.

### 3. Demander l’export

Dans le bloc **« Demander un export »**, un paragraphe explique ce que contient
l’archive. Cliquez sur le bouton **« Demander un export »**.

→ **Ce qui se passe** : la page revient sur elle-même et un bandeau vert annonce
**« Export demandé »**. La préparation part en tâche de fond : le lien de
téléchargement apparaîtra ici dès que l’archive sera prête.

### 4. Attendre que l’archive soit prête

Dans **« Exports précédents »**, la ligne de votre export porte le statut
**« En attente »**, puis **« En cours »**, puis **« Prêt »**. Rechargez la page
de temps en temps.

→ **Ce qui se passe** : le Core rassemble les données du projet et construit
l’archive. Tant qu’elle n’est pas prête, il n’y a rien à prendre.

### 5. Télécharger l’archive

Quand le statut affiche **« Prêt »**, cliquez sur **« Télécharger »**, tout à
droite de la ligne. La colonne qui suit le statut vous dit jusqu’à quelle date
et quelle heure le fichier peut être pris.

→ **Ce qui se passe** : le navigateur enregistre le fichier. Son nom reprend les
premiers caractères de l’identifiant du projet, puis la date et l’heure de la
demande : `export-3f2a91c4-20261031-142530.zip`. Le lien qui a servi à ce
téléchargement n’existait que le temps du clic.

### 6. Ouvrir l’archive

Décompressez le fichier : vous obtenez un dossier de fichiers texte, un par
type d’objet, et un fichier d’index.

Chaque fichier texte contient une ligne par objet, au format JSON Lines — la
même forme que celle que l’API renvoie : n’importe quel outil sait la lire. Le
fichier d’index (`manifest.json`) donne la date de l’export et le nombre de
lignes de chaque fichier.

Les fichiers de l’archive :

- le commerce : `customers.jsonl`, `plans.jsonl`, `subscriptions.jsonl`,
  `invoices.jsonl`, `usage.jsonl` ;
- l’argent : `payments.jsonl`, `wallets.jsonl`, `wallet_transactions.jsonl` ;
- les écritures : `ledger_transactions.jsonl`, `trial_balance.jsonl` ;
- et l’index : `manifest.json`.

→ **Ce qui se passe** : l’archive est une photographie du projet au moment de la
demande. Les factures et la balance y sont exactes à cette date ; les autres
fichiers ont été lus pendant la préparation.

## Ce que vous voyez

| Sur l’écran | Ce que ça veut dire |
|-------------|--------------------|
| **« Demander un export »** | Le bloc qui déclenche une nouvelle demande d’archive. |
| **« Exports précédents »** | Les demandes déjà faites sur ce projet, les plus récentes d’abord. |
| **« Demandé le »** | Le jour et l’heure de la demande. |
| **« Par »** | Le compte de la console qui a demandé l’export. |
| **« Statut »** | Où en est la préparation : **« En attente »**, **« En cours »**, **« Prêt »**, **« Échec »**, ou **« Fichier supprimé »** quand le fichier a été effacé. |
| **« Télécharger »** | Le bouton qui fabrique un lien de téléchargement, puis l’ouvre. |
| **« Export demandé »** (bandeau vert) | La demande est enregistrée ; l’archive se prépare en tâche de fond. |
| **« Le lien de téléchargement est généré au clic et valable 15 minutes. »** | Le lien n’existe qu’au moment où vous cliquez, et il ne sert qu’une fois. |
| **« Aucun export pour ce projet. »** | Aucune demande n’a encore été faite sur ce projet. |

## Si ça ne marche pas

| Ce que vous lisez | Ce que ça veut dire | Ce que vous faites |
|-------------------|---------------------|-------------------|
| Le statut reste **« En attente »** ou **« En cours »** | La préparation n’avance pas. | Attendez quelques minutes et rechargez la page. Si rien ne change au bout d’une heure, demandez un nouvel export. |
| **« Échec »** | La préparation n’a pas abouti. | Demandez un nouvel export : la ligne de l’échec reste dans la liste, elle sert de trace. |
| **« Fichier supprimé »** | Les sept jours sont passés ; le fichier a été effacé. | Demandez un nouvel export. |
| Un bandeau rouge vous dit que cet export n’a plus de fichier à télécharger | Le fichier n’est pas prêt, ou il n’existe plus. | Regardez le statut : s’il est **« En cours »**, attendez ; s’il est **« Échec »** ou **« Fichier supprimé »**, demandez un nouvel export. |
| Le téléchargement ne démarre pas | Le lien a expiré — quinze minutes — ou il a déjà servi. | Cliquez de nouveau sur **« Télécharger »** : un lien neuf est fabriqué. |
| Le fichier téléchargé ne s’ouvre pas | Le téléchargement a été interrompu. | Recommencez, et vérifiez que le fichier est arrivé en entier. |

## Et ensuite

- Vous voulez **savoir comment va le projet** : parcourez [F19 — Piloter par les
  indicateurs](F19-piloter-par-les-indicateurs.md).
- Vous voulez **les factures une par une** : sur la page du projet, le lien
  **« Factures de ce projet »** mène à la liste.
