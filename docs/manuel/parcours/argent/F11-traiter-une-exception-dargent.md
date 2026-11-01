---
numero: F11
titre: Traiter une exception d'argent
situation: L'argent ne s'équilibre pas
famille: argent
famille_titre: Suivre et reprendre l'argent
profil: Opérateur
temps: 10 minutes
---

## Ce qu’il faut savoir avant

- Une exception d’argent, c’est une somme que la plateforme **n’a pas pu imputer** —
  le plus souvent parce que le prestataire de paiement a confirmé un autre montant que
  celui demandé — ou un **contrôle comptable en échec**.
- **Rien n’est crédité tant qu’une personne n’a pas décidé.** L’argent concerné attend
  dans un compte d’attente, à l’abri des décisions de l’opérateur.
- Une exception se **résout** (vous dites où va la somme) ou se **refuse**. Au-delà d’un
  seuil de montant, la décision doit être **confirmée par une seconde personne** : vous
  ne pouvez pas valider votre propre décision.
- Une exception ne bouge **aucun argent** tant qu’elle n’est pas résolue. Une décision
  refusée laisse l’exception exactement où elle était.

## Les étapes

### 1. Ouvrez la file des exceptions

Dans la barre latérale, **« Exceptions d’argent »**. Un nombre rouge s’affiche quand
des exceptions attendent une décision.

### 2. Choisissez celle que vous allez traiter

Les filtres **« Toutes »**, **« Ouvertes »**, **« À valider »**, **« Résolues »** vous
permettent de ne garder que ce qui vous intéresse. Le tableau donne, pour chaque
exception, son **« Type »** — **« Écart de montant »**, **« Écart de devise »**,
**« Trop-perçu »**, **« Paiement tardif »**, **« Encaissement non imputé »**,
**« Écart de rapprochement »**, **« Contrôle comptable en échec »** —, sa
**« Gravité »**, le **« Projet »**, le **« Montant »** en jeu et le
**« Montant en attente »**.

### 3. Ouvrez l’exception

Cliquez sur **« Traiter »**. La fiche donne le **« Montant en jeu »**, le
**« Encore en attente »**, la **« Gravité »**, le **« Projet »**, le **« Paiement »**
concerné, la date d’ouverture, et la **« Référence externe »** — le numéro de
l’opération du côté du prestataire.

### 4. Dites où va la somme

Dans la rubrique **« Décider »**, choisissez une **« Résolution »** :

- **« Remboursé hors plateforme »** — l’argent a été rendu au client en dehors de la
  plateforme. La **« Référence du remboursement »** est alors obligatoire : c’est la
  preuve du remboursement (un numéro de virement, un reçu).
- **« Passé en revenu du projet »** — l’argent reste au projet, comme un revenu.
- **« Imputé à un portefeuille »** — l’argent est versé sur le portefeuille concerné.
  Ce choix n’est proposé que lorsqu’un portefeuille est en cause.

Saisissez ensuite un **« Motif »** — il est obligatoire — et cliquez sur
**« Enregistrer la décision »**.

→ **Ce qui se passe** : la décision est enregistrée. Si le montant dépasse le seuil du
projet, elle n’est pas encore appliquée : l'exception passe **« À valider »** et attend
une seconde personne. Sinon, elle est appliquée immédiatement et l'exception devient
**« Résolue »**.

### 5. Faites valider par un collègue

Sur l'exception en attente, la rubrique **« Validation par une seconde personne »**
propose **« Valider et comptabiliser »** et **« Refuser »**. Si c’est vous qui avez
proposé la résolution, la page vous dit qu’une autre personne doit la valider.

→ **Ce qui se passe** : **« Valider et comptabiliser »** écrit le mouvement dans la
comptabilité du projet. **« Refuser »** écarte la résolution : l’exception rouvre, et
l’argent reste en attente.

## Ce que vous voyez

| Sur l’écran | Ce que ça veut dire |
|-------------|--------------------|
| **« Montant en jeu »** | Le montant que l’exception concerne. |
| **« Encore en attente »** | La part de ce montant qui n’a pas encore été déplacée. |
| **« Montant en attente »** (dans la liste) | La même chose, vue depuis la file : c’est cette somme qui attend une décision. |
| **« Gravité »** | **« Critique »**, **« Haute »**, **« Moyenne »** ou **« Basse »** : à quel point le déséquilibre gêne. |
| **« Type »** | La nature du problème : un écart de montant, un trop-perçu, un encaissement qui n’a pas été imputé… |
| **« Résolution »** | Ce que vous décidez de faire de la somme. |
| **« Référence externe »** | Le numéro de l’opération chez le prestataire : c’est par là qu’on vérifie. |
| **« Proposée par »** / **« Validée par »** | Qui a proposé la décision, qui l’a validée. |

## Si ça ne marche pas

| Ce que vous lisez | Ce que ça veut dire | Ce que vous faites |
|-------------------|---------------------|-------------------|
| **« Vous avez proposé cette résolution : une autre personne doit la valider. »** | Vous êtes la personne qui a décidé : la règle des deux regard interdit que vous validiez vous-même. | Faites valider par un collègue. |
| **« Résolution enregistrée. Elle attend la validation d'une seconde personne (montant au-delà du seuil). »** | La décision est prise, mais pas appliquée : le montant dépasse le seuil du projet. | Faites valider la décision par un collègue. |
| **« Résolution validée et écrite au grand livre. »** | La décision est appliquée : le mouvement est dans la comptabilité du projet. | Vérifiez-la dans la comptabilité du projet. |
| **« Résolution refusée : l'exception est de nouveau ouverte. »** | Un collègue a écarté votre décision. | Relisez le motif, puis proposez de nouveau si nécessaire. |
| **« Un remboursement hors plateforme exige sa référence »** | Vous avez choisi **« Remboursé hors plateforme »** sans indiquer la référence. | Saisissez le numéro du virement ou du reçu dans **« Référence du remboursement »**, puis enregistrez de nouveau. |
| **« Aucune exception d'argent. »** | Il n’y a rien à traiter dans ce filtre. | Changez de filtre, ou vérifiez que vous êtes sur la bonne organisation. |

## Et ensuite

- Une écriture est fausse, et il faut la corriger : parcourez [F12 — Corriger une erreur
  d’écriture](F12-corriger-une-erreur-decriture.md).
- Un paiement est bloqué : parcourez [F10 — Retrouver ou relancer un
  paiement](F10-retrouver-ou-relancer-un-paiement.md).
- Vous voulez comprendre d’où vient l’argent : parcourez [F13 — Comprendre d’où vient
  l’argent](F13-comprendre-dou-vient-largent.md).
