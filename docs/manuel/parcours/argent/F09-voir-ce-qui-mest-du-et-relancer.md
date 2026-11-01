---
numero: F09
titre: Voir ce qui m'est dû et relancer
situation: Suivre les créances
famille: argent
famille_titre: Suivre et reprendre l'argent
profil: Commerce
temps: 5 minutes
---

## Ce qu’il faut savoir avant

- Les créances ne sont pas un calcul refait facture par facture : ce sont les sommes que
  **les comptes du projet** disent qu’on vous doit. Une facture réglée par virement ne
  sort des créances que lorsqu’elle a été **enregistrée comme encaissement**.
- **« En retard »** veut dire que l’échéance est passée, pas que le client ne paiera
  pas. Le nombre de jours de retard est écrit dans la colonne **« Retard »**.
- Une créance **abandonnée** ne l’est jamais par l’effet du temps : c’est un acte
  comptable que vous décidez, facture par facture.
- La console **ne relance personne à votre place**. Elle vous dit ce qui est dû, à qui
  et depuis combien de temps ; la relance, c’est vous qui la faites, par l’espace
  client du client ou par votre propre message.

## Les étapes

### 1. Ouvrez la fiche du projet

Dans la barre latérale, cliquez sur **« Projets »**, puis sur le projet concerné. La
fiche du projet est une page de liens : **« Créances »**, **« Indicateurs »**,
**« Factures de ce projet »**, **« Clients de ce projet »**, **« Comptabilité et balance »**.

### 2. Ouvrez la page des créances

Cliquez sur **« Créances »**. Trois totaux s’affichent en haut : **« Créances »** — ce
qu’on vous doit ; **« En retard »** — échue, non encaissée ; **« Irrécouvrables »** —
abandonné, par votre décision.

→ **Ce qui se passe** : la page lit les comptes du projet, pas les factures une à une.
Les montants affichés sont donc ceux que la comptabilité retient, y compris pour une
facture réglée par un chemin que vous ne connaissez pas.

### 3. Repérez la facture la plus ancienne

Le tableau **« Factures ouvertes »** liste une ligne par facture encore ouverte. La
colonne **« Reste dû »** dit ce qu’il reste à encaisser ; la colonne **« Retard »** dit
depuis combien de jours la facture est échue, et passe en rouge au-delà de trente
jours. Commencez par la ligne la plus haute : le plus ancien dû d’abord.

### 4. Ouvrez la facture, puis le client

Cliquez sur le numéro de la facture : la fiche de la facture montre ses lignes, son
**« Total »**, son **« Déjà réglé »** et l’historique de ses encaissements. Revenez à
la fiche du projet, ouvrez **« Clients de ce projet »**, puis la fiche du client : la
rubrique **« Factures récentes »** donne, pour chaque facture, le **« Total »** et le
**« Payé »** ; la rubrique **« Règlements »** retrace ce que le client a réellement
réglé, et par quel chemin.

### 5. Mesurez l’ancienneté d’un coup

Sur la fiche du projet, ouvrez **« Indicateurs »**. Le tableau **« Créances par
ancienneté »** range le dû par tranches : **« Non échues »**, **« 1 à 30 jours »**,
**« 31 à 60 jours »**, **« Plus de 60 jours »**. C’est la vue qui dit d’un coup d’œil
ce qui est le plus vieux.

### 6. Relancez

La fiche client vous donne la référence du client et le montant qui lui reste dû.
Relancez-le par votre propre message, ou depuis son espace client si le projet en a
un : le client y voit ses factures en attente et règle lui-même.

→ **Ce qui se passe** : rien ne part de la console. Il n’existe pas de bouton de
relance : la console vous donne ce qu’il faut pour relancer — qui, combien, depuis
quand — et la suite vous appartient.

## Ce que vous voyez

| Sur l’écran | Ce que ça veut dire |
|-------------|--------------------|
| **« Créances »** (montant en haut de page) | Ce que les comptes du projet disent qu’on vous doit, toutes factures confondues. |
| **« En retard »** | La part de cette somme dont l’échéance est déjà passée. |
| **« Irrécouvrables »** | Ce qui a été abandonné, par un acte de votre part. |
| **« Numéro »** | Le numéro de la facture. Une facture pas encore émise s’affiche **« brouillon »**. |
| **« Client »** | La référence du client chez le projet : c’est celle que vous citez dans vos messages. |
| **« Échéance »** | La date à laquelle la facture devait être payée. |
| **« Total »** | Le montant de la facture. |
| **« Reste dû »** | Ce qu’il reste à encaisser sur cette facture. |
| **« Retard »** | Le nombre de jours depuis l’échéance. Un tiret quand la facture n’est pas échue, ou n’a pas d’échéance. |
| **« Abandonnée le »** | La date de l’acte par lequel la créance a été abandonnée. |
| **« Non échues »**, **« 1 à 30 jours »**, **« 31 à 60 jours »**, **« Plus de 60 jours »** | Le dû réparti par ancienneté. |
| **« Ce projet est-il prêt à encaisser ? »** | La liste de contrôle du projet : ce qui lui manque encore pour pouvoir encaisser. Une coche verte marque ce qui est en place, un point d’exclamation ce qui reste à faire. |

## Si ça ne marche pas

| Ce que vous lisez | Ce que ça veut dire | Ce que vous faites |
|-------------------|---------------------|-------------------|
| **« Aucune facture ouverte. »** | Le projet n’a aucune facture encore ouverte : il n’y a rien à relancer de ce côté. | Vérifiez que vous êtes sur le bon projet — le nom du projet est écrit en haut de la page — puis ouvrez **« Indicateurs »** pour voir le dû par ancienneté. |
| **« Aucune créance abandonnée. »** | Aucune créance n’a été abandonnée sur ce projet. | Rien à faire. |
| Un bandeau orange : le projet est actif mais **pas encore prêt à encaisser** | Il lui manque un élément de branchement (une offre publiée, une clé d’API déjà utilisée, un point de notification actif…). | Lisez la liste sous le bandeau et complétez-la avant de facturer. |
| Les courbes sont remplacées par **« Pas encore d’historique »** | Le projet n’a pas encore une journée d’histoire : les courbes se remplissent la nuit. | Les chiffres du jour, eux, sont déjà là. Revenez demain. |

## Et ensuite

- Un paiement est bloqué et vous ne savez pas où il en est : parcourez [F10 — Retrouver
  ou relancer un paiement](F10-retrouver-ou-relancer-un-paiement.md).
- L’argent ne s’équilibre pas : parcourez [F11 — Traiter une exception
  d’argent](F11-traiter-une-exception-dargent.md).
- Vous voulez comprendre d’où vient l’argent : parcourez [F13 — Comprendre d’où vient
  l’argent](F13-comprendre-dou-vient-largent.md).
