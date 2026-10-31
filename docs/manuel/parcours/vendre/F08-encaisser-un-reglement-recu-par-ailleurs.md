---
numero: F08
titre: Encaisser un règlement reçu par ailleurs
situation: Enregistrer un règlement reçu par ailleurs
famille: vendre
famille_titre: Vendre et encaisser
profil: Commerce
temps: 5 minutes
---

## Ce qu’il faut savoir avant

- **Un règlement reçu par ailleurs est un reçu.** Il porte une méthode — virement,
  espèces, chèque ou autre —, une référence et un montant. C’est ce trio qui
  permet, plus tard, de relier l’argent reçu à ce que le client devait.
- **Un reçu ne s’enregistre que sur une facture ouverte.** Une facture encore en
  brouillon n’accepte rien, et une facture déjà payée non plus.
- **Le montant ne peut pas dépasser ce qui reste dû.** Un reçu supérieur au solde
  restant est refusé : il n’y a pas de surpaiement. Réglez deux factures si le
  client a envoyé plus que ce qu’il devait sur celle-ci.
- **Le reçu se saisit dans la devise de la facture.** Une autre devise est
  refusée.
- **Au-dessus du seuil du projet, le reçu attend une validation.** Le seuil se
  règle dans les **« Réglages du commerce »** du projet. Un reçu sous le seuil
  compte tout de suite ; au-dessus, il reste en attente jusqu’à ce qu’il soit
  validé. Tant qu’il attend, il ne compte dans rien.

## Les étapes

### 1. Ouvrir la facture à régler

Depuis la fiche du projet, le lien **« Factures de ce projet »**, puis
**« Ouvrir »** en face de la facture. La page de la facture s’ouvre.

→ **Ce qui se passe** : la facture affiche ses lignes, son total et ce qui est
déjà réglé. Le tableau **« Encaissements de la facture »** montre ce qui a
déjà été reçu : les **« Paiement en ligne »** d’un côté, les reçus hors ligne de
l’autre.

### 2. Remplir le reçu

Sur la facture ouverte, la carte **« Encaissement hors ligne »**.

- **« Méthode »** — obligatoire : **« Virement »**, **« Espèces »**,
  **« Chèque »** ou **« Autre »**.
- **« Référence »** — obligatoire. Le numéro du virement, la référence du chèque :
  c’est ce qui permet de retrouver la trace du paiement plus tard.
- **« Montant (unités mineures) »** — obligatoire. 50 000 XAF se saisit
  `50000`.
- **« Devise »** — obligatoire, celle de la facture.
- **« Reçu le »** — obligatoire. La date où l’argent est arrivé, pas celle où
  vous enregistrez le reçu.
- **« Note »** — facultatif. Une précision libre, si vous en avez une.

Cliquez sur **« Enregistrer l’encaissement »**.

→ **Ce qui se passe** : le reçu est enregistré et il apparaît dans le tableau
**« Encaissements de la facture »**. S’il est sous le seuil du projet, il est
comptabilisé aussitôt : la facture porte cette somme dans son **« Déjà réglé »**,
et son statut peut passer à **« Payée »**. Au-dessus du seuil, il reste
**« En attente »** : rien n’est compté tant qu’il n’est pas validé.

### 3. Valider le reçu, s’il est en attente

Le lien **« Tous les encaissements du projet »** mène à la page
**« Encaissements hors ligne »**. Le filtre **« À valider »** liste les reçus
qui attendent.

→ **Ce qui se passe** : chaque ligne porte son **« Référence »**, son
**« Montant »**, sa **« Méthode »**, sa date de réception, son **« État »** et
une colonne **« Validation »**.

### 4. Valider ou refuser

Le bouton **« Valider »** compte le reçu ; le bouton **« Refuser »** ne le compte
pas.

→ **Ce qui se passe** : avant de compter, la console vous demande de confirmer
votre identité (**« Confirmez votre identité »**). Vous revenez ensuite sur la
page des encaissements, et il reste à cliquer une seconde fois sur
**« Valider »** : votre confirmation d’identité vaut cinq minutes. Le reçu
validé est alors **« Comptabilisé »** : il est inscrit dans les comptes, la
facture porte la somme dans son **« Déjà réglé »**, et son statut peut passer à
**« Payée »**. Un reçu refusé est **« Refusé »** : rien n’est compté, mais la
trace reste, et vous pouvez enregistrer un nouveau reçu.

### 5. Suivre les règlements du client

Sur la fiche du client, la carte **« Règlements »** regroupe tout ce que le
client a réglé, dans l’ordre : les paiements en ligne et les reçus hors ligne,
avec leur montant, leur référence, leur date et leur état.

→ **Ce qui se passe** : c’est le meilleur endroit pour vérifier ce qu’un client
a déjà payé, et ce qui ne l’est pas encore.

## Ce que vous voyez

| Sur l’écran | Ce que ça veut dire |
|-------------|--------------------|
| **« Méthode »** | Comment l’argent est arrivé : **« Virement »**, **« Espèces »**, **« Chèque »** ou **« Autre »**. |
| **« Référence »** | La trace du paiement : numéro de virement, numéro de chèque. C’est elle qu’on donne au client quand il demande où est son règlement. |
| **« Reçu le »** | La date où l’argent est arrivé, celle que vous avez saisie — pas la date de l’enregistrement. |
| **« État »** — **« En attente »** | Le reçu est enregistré, mais il n’est pas compté : il attend sa validation (au-dessus du seuil du projet). |
| **« État »** — **« Comptabilisé »** | Le reçu est validé : il est inscrit dans les comptes et rapproché de la facture. |
| **« État »** — **« Refusé »** | Le reçu a été refusé : rien n’est compté, la trace reste. |
| **« Validation »** | Les deux boutons **« Valider »** et **« Refuser »**, présents seulement sur un reçu **« En attente »**. |
| **« À valider »**, **« Comptabilisés »**, **« Refusés »** | Les trois filtres de la page des encaissements hors ligne. |
| **« Origine »** (colonne du tableau des encaissements d’une facture) | D’où vient l’argent : **« Paiement en ligne »** pour un paiement fait par le client, **« Hors ligne »** suivi de sa méthode pour un reçu que vous avez enregistré. |
| **« Règlements »** (carte de la fiche client) | Tout ce que le client a réglé, dans l’ordre chronologique. |

## Si ça ne marche pas

| Ce que vous lisez | Ce que ça veut dire | Ce que vous faites |
|-------------------|---------------------|-------------------|
| **« Le formulaire n’a pas pu être enregistré »** et une liste de messages | Le reçu a été refusé : une méthode ou une date manque, le montant n’est pas un nombre. | Corrigez les champs signalés et enregistrez de nouveau. |
| Un bandeau rouge après **« Enregistrer l’encaissement »**, sans champ en cause | La facture n’accepte pas ce reçu : elle n’est plus ouverte, elle est déjà payée, le montant dépasse ce qui reste dû, ou la devise ne va pas. | Vérifiez le statut et le **« Déjà réglé »** de la facture, et saisissez le montant exact qui reste. |
| **« Ressource introuvable. »** | La facture n’existe pas dans ce projet. | Revenez à la page **« Factures »** et rouvrez-la par **« Ouvrir »**. |
| Votre reçu n’apparaît pas dans le filtre **« À valider »** | Il est sous le seuil du projet : il a été comptabilisé au moment de l’enregistrement. | Consultez le filtre **« Comptabilisés »**. |
| **« Confirmez votre identité »** s’ouvre à la place de la page | Valider un reçu est un acte sensible : la console veut une preuve fraîche que c’est bien vous. | Saisissez le code à 6 chiffres de votre application d’authentification et cliquez sur **« Confirmer »**. Vous revenez ensuite sur la page des encaissements : cliquez encore une fois sur **« Valider »**. |
| Le reçu reste **« En attente »** après votre validation | Quelqu’un d’autre l’a déjà validé ou refusé entre-temps. | Rechargez la page et regardez son **« État »**. |

## Et ensuite

- Vous voulez **facturer une période** : parcourez [F07 — Émettre une facture](F07-emettre-une-facture.md).
- Vous voulez **voir ce qui reste dû et relancer les clients** : parcourez [F09 — Voir ce qui m’est dû et relancer](../argent/F09-voir-ce-qui-mest-du-et-relancer.md).
