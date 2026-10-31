---
numero: F07
titre: Émettre une facture
situation: Facturer une période
famille: vendre
famille_titre: Vendre et encaisser
profil: Commerce
temps: 5 minutes
---

## Ce qu’il faut savoir avant

- **Une facture naît en brouillon.** Tant qu’elle est en brouillon, elle n’a ni
  numéro ni date d’émission, et vous pouvez encore la corriger. Une facture
  émise ne se modifie plus.
- **Émettre n’est pas encaisser.** Émettre, c’est donner un numéro à la facture et
  inscrire ce qu’elle doit dans les comptes du client. L’argent n’entre pas dans
  la facture à ce moment-là : il faut l’encaisser ensuite, en ligne ou hors ligne
  (parcours [F08](F08-encaisser-un-reglement-recu-par-ailleurs.md)).
- **Les montants se saisissent en unités mineures.** 50 000 XAF se saisit
  `50000`. Si vous laissez la devise ou la taxe vides, ce sont les réglages du
  projet qui s’appliquent.
- **La taxe se compte en points de base.** 100 points de base font 1 % : pour une
  taxe de 5 %, on saisit 500.
- **Le numéro ne saute pas.** À l’émission, la facture reçoit le numéro suivant
  de la série du projet, sans trou. Une facture annulée garde son numéro.

## Les étapes

### 1. Ouvrir la liste des factures

Depuis la fiche du projet, le lien **« Factures de ce projet »**. La page
**« Factures »** s’ouvre : un filtre **« Statut »**, le bouton
**« Nouvelle facture »**, et un tableau aux colonnes **« Numéro »**,
**« Client »**, **« Total »**, **« Statut »** et **« Échéance »**.

→ **Ce qui se passe** : la liste s’affiche. Le filtre **« Statut »** permet de
ne garder que les factures **« Brouillon »**, **« Ouverte »**, **« Payée »**,
**« Annulée »** ou **« Irrécouvrable »** ; le bouton **« Filtrer »** applique
le choix.

### 2. Créer le brouillon

Cliquez sur **« Nouvelle facture »**. Le formulaire s’ouvre.

- **« Référence du client »** — obligatoire.
  **« La référence que le projet connaît pour ce client. »**
- **« Devise »** — facultatif. La devise du projet s’applique si le champ reste
  vide.
- **« Taxe (points de base) »** — facultatif. Le taux du projet s’applique si le
  champ reste vide.
- **« Échéance »** — facultatif. Sans échéance, la facture est payable dès sa
  réception.
- **« Lignes »** — trois lignes sont proposées. Chacune porte une
  **« Désignation »**, une **« Qté »** (pré-remplie à 1), un
  **« Prix unitaire »** et, si besoin, sa propre **« Taxe (points de base) »**. Une
  ligne vide est ignorée : laissez-la en l’état si vous n’avez qu’une ligne à
  facturer.

Cliquez sur **« Créer le brouillon »**.

→ **Ce qui se passe** : la facture est enregistrée comme brouillon et sa page
s’ouvre. Vous pouvez encore revenir dessus et corriger les lignes tant que vous
ne l’avez pas émise.

### 3. Relire la facture

La page de la facture affiche son **« Numéro »** (le mot **« brouillon »** tant
qu’elle n’est pas émise), son **« Émise le »**, son **« Échéance »**, son
**« Sous-total »**, sa **« Taxe »**, son **« Total »** et ce qui est déjà
réglé (**« Déjà réglé »**). Le tableau **« Lignes de la facture »** détaille ce
qui a été facturé : **« Désignation »**, **« Qté »**, **« Prix unitaire »**,
**« Montant »** et **« Taxe »**.

→ **Ce qui se passe** : rien n’est encore dû : tant que la facture est en
brouillon, elle n’existe pas pour le client et rien n’est inscrit dans les
comptes.

### 4. Émettre la facture

En bas de la page, la carte **« Émettre la facture »** et son bouton
**« Émettre »**. Une confirmation s’ouvre ; cliquez sur **« Émettre la facture »**.

**« L’émission attribue un numéro sans trou, inscrit la créance dans les comptes et fige le document. Elle est irréversible : seule l’annulation, elle aussi auditée, permet de revenir en arrière. »**

→ **Ce qui se passe** : avant de faire le geste, la console vous demande de
confirmer votre identité : **« Confirmez votre identité »**, avec le code à
6 chiffres de votre application d’authentification. Saisissez-le et cliquez sur
**« Confirmer »**.

→ **Ce qui se passe** : vous revenez à la facture, et il reste à cliquer une
seconde fois sur **« Émettre »** : votre confirmation d’identité vaut cinq
minutes. La facture est alors émise. Elle porte son numéro, son statut
**« Ouverte »** et sa date d’émission. Ce qu’elle doit est inscrit dans les
comptes du client. L’argent, lui, n’est pas encore arrivé : la facture est
ouverte, pas payée.

## Ce que vous voyez

| Sur l’écran | Ce que ça veut dire |
|-------------|--------------------|
| **« Statut »** — **« Brouillon »** | La facture n’existe pas encore pour le client : elle n’a ni numéro ni créance. Vous pouvez encore la corriger. |
| **« Statut »** — **« Ouverte »** | La facture est émise : elle est due, et l’argent peut être encaissé. |
| **« Statut »** — **« Payée »** | La facture a été entièrement réglée. |
| **« Statut »** — **« Annulée »** | La facture a été annulée : ce qu’elle doit est revenu en arrière dans les comptes. |
| **« Statut »** — **« Irrécouvrable »** | Vous avez décidé de ne plus chercher cette somme : elle quitte le compte du client et rejoint les créances irrécouvrables. |
| **« Échéance »** | La date à laquelle la facture est due. Sans échéance, la facture est payable dès sa réception. |
| **« Total »** | Ce que le client doit : le sous-total, plus la taxe. |
| **« Déjà réglé »** | Ce qui a déjà été encaissé sur cette facture. Tant que ce montant n’atteint pas le total, la facture reste **« Ouverte »**. |
| **« Encaissements de la facture »** | L’historique de ce qui a été réglé : d’un côté les paiements en ligne, de l’autre les reçus hors ligne. |
| **« Annuler la facture »** | On contrepasse l’écriture d’émission : la créance revient dans les comptes. L’annulation est refusée si quelque chose a déjà été réglé, et elle exige un motif. |
| **« Passer en irrécouvrable »** | On déclare la créance perdue. C’est un acte comptable, jamais un effet du temps : il faut le faire, et il est journalisé. |

## Si ça ne marche pas

| Ce que vous lisez | Ce que ça veut dire | Ce que vous faites |
|-------------------|---------------------|-------------------|
| **« Le formulaire n’a pas pu être enregistré »** et une liste de messages | La facture a été refusée : une ligne est vide, la quantité ou le prix ne sont pas des nombres, ou la date d’échéance est illisible. | Corrigez ce qui est signalé dans le formulaire, puis enregistrez de nouveau. |
| **« Ressource introuvable. »** | Le client saisi n’existe pas dans ce projet. | Vérifiez la référence du client, ou créez d’abord sa fiche (parcours [F05](F05-enregistrer-un-client.md)). |
| Le bouton **« Émettre »** n’apparaît pas | La facture n’est plus en brouillon : elle est déjà émise, annulée ou payée. | Une facture émise ne se modifie plus : annulez-la si elle est fausse, puis refaites-la. |
| **« Confirmez votre identité »** s’ouvre à la place de la facture | L’émission est un acte sensible : la console veut une preuve fraîche que c’est bien vous. | Saisissez le code à 6 chiffres de votre application d’authentification et cliquez sur **« Confirmer »**. Vous revenez ensuite à la facture : cliquez encore une fois sur **« Émettre »**. |
| **« Code invalide, expiré ou déjà utilisé. Attendez le prochain code de votre application. »** | Le code est faux, périmé, ou déjà utilisé. | Ouvrez votre application d’authentification, attendez le code suivant et saisissez-le. |
| **« Annuler la facture »** est refusé | Quelque chose a déjà été réglé sur cette facture : on ne peut pas annuler une facture à laquelle de l’argent a déjà été imputé. | Enregistrez d’abord le règlement (parcours [F08](F08-encaisser-un-reglement-recu-par-ailleurs.md)), puis traitez la facture comme une créance à recouvrer. |

## Et ensuite

- L’argent est arrivé **par virement, en espèces ou par chèque** : parcourez [F08 — Encaisser un règlement reçu par ailleurs](F08-encaisser-un-reglement-recu-par-ailleurs.md).
- Vous voulez **savoir ce qui vous est dû et relancer** : parcourez [F09 — Voir ce qui m’est dû et relancer](../argent/F09-voir-ce-qui-mest-du-et-relancer.md).
