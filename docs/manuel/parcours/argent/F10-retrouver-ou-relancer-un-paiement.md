---
numero: F10
titre: Retrouver ou relancer un paiement
situation: Un paiement est bloqué
famille: argent
famille_titre: Suivre et reprendre l'argent
profil: Support
temps: 5 minutes
---

## Ce qu’il faut savoir avant

- Un paiement **réussi** n’est pas forcément de l’argent encaissé par le projet. Un
  paiement peut être un **encaissement pur** : la case **« Portefeuille à créditer »**
  de la fiche affiche alors **« — (encaissement pur, aucun portefeuille) »**.
- La plateforme **ne crédite jamais un portefeuille toute seule**. L’argent n’arrive sur
  un portefeuille que si l’application qui a lancé le paiement avait demandé, **au
  moment de le lancer**, que le paiement y verse. Ce voyage se décide avant, jamais à
  la réception.
- Chaque fiche de paiement porte une **chronologie** : la rubrique **« Parcours »**
  liste les étapes, de la première à la dernière, avec leur date. C’est là qu’on voit
  où le paiement s’est arrêté.
- **Réconcilier** ne relance pas le client : la plateforme demande au prestataire de
  paiement où en est le paiement, et corrige l’état de la plateforme si le statut a
  changé. Elle ne refait jamais un paiement à la place du client.

## Les étapes

### 1. Ouvrez la liste des paiements

Dans la barre latérale, la rubrique **« Argent »** mène à **« Paiements »**. La liste
s’ouvre sur tous les projets : une ligne par paiement, de la plus récente à la plus
ancienne.

### 2. Trouvez le paiement

Trois filtres : **« Projet »**, **« Canal »**, **« Statut »**, puis le bouton
**« Filtrer »**. Le menu **« Statut »** propose **« En cours »**, **« En traitement »**,
**« Réussi »**, **« Échec »**, **« Expiré »**, **« Remboursé »**, **« Annulé »**. Si la
liste est vide, le bouton **« Réinitialiser »** remet les filtres à zéro.

### 3. Ouvrez la fiche du paiement

Cliquez sur **« Voir »** en bout de ligne. La fiche affiche le montant, la devise, le
**« Canal »**, le **« Prestataire »** — le service de paiement qui a traité ce
paiement —, la **« Référence du prestataire »**, les dates, et la case **« Portefeuille
à créditer »**.

### 4. Lisez le parcours

Descendez jusqu’à la rubrique **« Parcours »**. Chaque étape y est datée et titrée : la
requête reçue, la création du paiement, l’appel au prestataire, la réponse envoyée, le
message de retour du prestataire, le changement d’état, le versement sur le
portefeuille, l’écriture dans la comptabilité, l’écart de montant, l’événement publié.
Le lien **« Voir le détail »** ouvre le détail d’une étape.

→ **Ce qui se passe** : la chronologie est reconstituée à partir de ce que la
plateforme a enregistré. Une étape manquante ne veut pas dire qu’elle n’a pas eu lieu :
elle veut dire que la plateforme n’en a rien su.

### 5. Réconciliez ce paiement

Si l’état affiché ne correspond pas à ce que le client raconte, cliquez sur
**« Réconcilier maintenant »**. Une confirmation s’ouvre, **« Réconcilier ce paiement ? »**,
avec le bouton **« Réconcilier »**.

→ **Ce qui se passe** : la plateforme demande au prestataire le statut réel de ce
paiement. Si le statut a changé, elle met à jour l’état du paiement — et, si le
paiement portait un portefeuille, le versement prévu par le paiement. Si rien n’a
changé, elle vous le dit.

### 6. Vérifiez les paiements bloqués d’un coup

Dans la barre latérale, **« Réconciliation »** ouvre la page qui annonce combien de
paiements vont être vérifiés. Un bouton **« Réconcilier »** permet d’en traiter un seul
depuis sa ligne ; plus bas, **« Lancer la réconciliation groupée maintenant »** vérifie
tous ceux de la liste, dans la limite que vous fixez (**« Nombre de lignes (max 20) »**).

→ **Ce qui se passe** : le bilan s’affiche — **« Vérifiés »**, **« Mis à jour »**,
**« Erreurs »**. Un **« Mis à jour »** à zéro signifie que la plateforme confronte les
statuts et que rien n’avait bougé.

## Ce que vous voyez

| Sur l’écran | Ce que ça veut dire |
|-------------|--------------------|
| **« Canal »** | Le canal de paiement par lequel la transaction est partie (carte, portefeuille mobile, virement…). |
| **« Prestataire »** | Le service de paiement qui a traité ce paiement. |
| **« Référence du prestataire »** | Le numéro de la transaction chez le prestataire : c’est celui que l’on demande en cas de litige. |
| **« Action attendue de vous »** | **« Oui »** quand le client doit encore faire quelque chose — payer sur une adresse de paiement, par exemple. |
| **« Ce qu'il reste à faire »** | Ce que le prestataire demande au client pour terminer le paiement. |
| **« Portefeuille à créditer »** | Le portefeuille qui recevra l’argent, s’il y en a un. |
| **« Parcours »** | La chronologie du paiement, étape par étape. |
| **« Bloqué depuis »** | Combien de temps le paiement attend, sans nouvelle. |
| **« Vérifiés »**, **« Mis à jour »**, **« Erreurs »** | Le bilan d’une réconciliation : combien de paiements ont été regardés, combien ont changé d’état, combien ont échoué. |

## Si ça ne marche pas

| Ce que vous lisez | Ce que ça veut dire | Ce que vous faites |
|-------------------|---------------------|-------------------|
| **« Aucun paiement ne correspond aux filtres. »** | Aucun paiement ne remplit les conditions que vous avez choisies. | Cliquez sur **« Réinitialiser »** pour tout rouvrir, puis filtrez de nouveau sur le seul projet. |
| **« Paiement introuvable pour ce projet. »** | Le paiement n’existe pas dans ce projet, ou votre compte n’a pas le droit de le voir. | Revenez à la liste et rouvrez-le par le lien **« Voir »** de sa ligne. |
| Un message rouge commence par **« Échec de la vérification auprès du provider »** | La demande au prestataire n’a pas abouti : réseau coupé, prestataire indisponible. Rien n’a été modifié. | Réessayez plus tard. Le mot provider est le terme employé à l’écran pour le prestataire de paiement. |
| **« Indisponible : aucun paiement bloqué à vérifier. »** | Aucun paiement n’attend de vérification : le bouton de réconciliation groupée est inactif. | Rien à faire — la plateforme vérifie seule, à intervalles réguliers, les paiements restés bloqués. |
| **« Réconciliation exécutée »** | La réconciliation groupée s’est terminée. | Lisez le bilan **« Vérifiés »**, **« Mis à jour »**, **« Erreurs »** juste en dessous. |
| **« aucun changement »** | Le prestataire confirme que le paiement est toujours dans le même état. | Le client n’a pas payé. Relancez-le, ou attendez la confirmation. |

## Et ensuite

- Vous voulez savoir ce qui vous est dû, et sur quelle facture : parcourez [F09 — Voir ce
  qui m’est dû et relancer](F09-voir-ce-qui-mest-du-et-relancer.md).
- L’argent ne s’équilibre pas : parcourez [F11 — Traiter une exception
  d’argent](F11-traiter-une-exception-dargent.md).
- Vous voulez comprendre d’où vient l’argent : parcourez [F13 — Comprendre d’où vient
  l’argent](F13-comprendre-dou-vient-largent.md).
