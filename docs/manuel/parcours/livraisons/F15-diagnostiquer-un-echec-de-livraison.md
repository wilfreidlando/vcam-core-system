---
numero: F15
titre: Diagnostiquer un échec de livraison
situation: Un événement n'est pas arrivé
famille: livraisons
famille_titre: Brancher et surveiller les livraisons
profil: Support
temps: 10 minutes
---

## Ce qu’il faut savoir avant

- **Une livraison qui échoue plusieurs fois est mise de côté.** Le Core ne la
  retente plus tout seul : c’est à vous de la rejouer, une fois la cause
  corrigée.
- **Rejouer ne supprime rien.** La ligne en échec reste telle quelle ; une
  nouvelle livraison est créée et remise en file. Vous gardez donc la trace de
  ce qui a échoué.
- **Un rejeu renvoie exactement le même événement, au même point d’entrée.** Si
  l’adresse est fausse ou si le secret ne correspond plus, il échouera encore.
- **Trois causes expliquent presque tous les échecs** : le destinataire refuse
  la livraison, l’adresse de réception est fausse, ou le secret de signature ne
  correspond plus.
- **Le destinataire reçoit l’identifiant de l’événement avec la livraison.**
  C’est ce numéro qu’il vous communiquera pour retrouver la ligne dans
  l’historique.

## Les étapes

### 1. Ouvrir l’historique des livraisons

Dans la barre latérale, **« Notifications automatiques »**. La page
**« Livraisons de webhooks »** s’ouvre : elle montre *toutes* les livraisons,
pas seulement celles qui ont échoué.

→ **Ce qui se passe** : le tableau des livraisons s’affiche. Un nombre rouge
dans la barre latérale annonce combien d’entre elles sont en échec.

### 2. Ne garder que les échecs

Dans le filtre **« Statut »**, choisissez **« En échec (mort) »**, puis cliquez sur
**« Filtrer »**.

→ **Ce qui se passe** : la liste se réduit aux livraisons mises de côté.

### 3. Lire la ligne en échec

Chaque ligne donne l’**« Adresse de réception »**, le **« Projet »**, le
**« Statut »**, le type d’événement, le nombre de **« Tentatives »**, la
**« Dernière erreur »** et la **« Dernière tentative »**.

Le petit chevron à gauche de la ligne ouvre le détail : **« Corps envoyé »** à
gauche, **« Réponse reçue »** à droite.

→ **Ce qui se passe** : vous voyez ce que le Core a réellement envoyé, et ce que
le destinataire a répondu. C’est là que se trouve la cause la plus fréquente :
une adresse qui répond par une erreur, ou une signature refusée.

### 4. Ouvrir la livraison

Cliquez sur l’**« Identifiant »** de la ligne. La page de la livraison s’ouvre.

→ **Ce qui se passe** : vous voyez l’**« Identifiant de la livraison »**, l’**« Identifiant de l'événement »**, le **« Point d'entrée »** — l’adresse de votre application qui reçoit les événements —, les dates (**« Dernière tentative »**, **« Livrée le »**, **« Prochain essai »**) et, si cette livraison est un rejeu, la ligne d’origine sous **« Rejouée depuis »**.

### 5. Lire la chronoologie des tentatives

Sous **« Tentatives »**, chaque essai est numéroté et dit ce que le Core en a
fait.

→ **Ce qui se passe** : vous reconstituez l’histoire. Un essai marqué
**« abandonnée (aucun nouvel essai) »** est le dernier : le Core a cessé d’essayer.
Un essai marqué **« nouvel essai programmé »** sera repris plus tard. Chaque essai
montre la requête envoyée (**« Requête envoyée »**) et la réponse du
destinataire (**« Réponse du récepteur »**).

### 6. Corriger la cause

- Si la réponse montre une **adresse fausse** : corrigez l’adresse du point
  d’entrée sur la page du projet.
- Si la réponse montre une **signature refusée** : le secret de votre
  application ne correspond plus à celui du point d’entrée. Faites tourner le
  secret, puis redémployez votre application.
- Si le point d’entrée a été **désactivé** : réactivez-le sur la page du projet.

→ **Ce qui se passe** : rien n’est livré tant que la cause n’est pas corrigée.

### 7. Rejouer la livraison

Sur la ligne en échec, ou sur la page de la livraison, cliquez sur **« Rejouer »**
et confirmez.

→ **Ce qui se passe** : une nouvelle livraison est créée et remise en file. Un
bandeau vous annonce **« Rejeu mis en file »**. La ligne d’origine reste dans
l’historique, avec son statut d’échec.

## Ce que vous voyez

| Sur l’écran | Ce que ça veut dire |
|-------------|--------------------|
| **« Notifications automatiques »** (barre latérale) | L’historique de tout ce que le Core a tenté de livrer. Le badge rouge compte les livraisons en échec. |
| **« En attente »** | La livraison est dans la file : elle n’a pas encore été tentée, ou son prochain essai n’est pas arrivé. |
| **« Livré »** | Le destinataire a accepté la livraison. |
| **« En échec (mort) »** | La livraison a échoué plusieurs fois et a été mise de côté. Elle attend une action de votre part. |
| **« Tentatives »** | Le nombre de fois où le Core a essayé. Huit tentatives ou plus, la pastille passe au rouge. |
| **« Dernière erreur »** | Le motif du dernier échec, tel que le Core l’a compris. |
| **« Dernière tentative »** | La date et l’heure du dernier essai. |
| **« Corps envoyé »** et **« Réponse reçue »** | Ce que le Core a réellement envoyé, et ce que le destinataire a répondu. |
| **« Point d'entrée »** | L’adresse de votre application qui reçoit les événements. |
| **« Prochain essai »** | La date du prochain essai, s’il en reste un prévu. |
| **« Rejouée depuis »** | La livraison d’origine, quand celle-ci est un rejeu. |
| **« Point d'entrée désactivé »** | Le point d’entrée est coupé : aucune livraison ni rejeu n’est possible tant qu’il l’est. |

## Si ça ne marche pas

| Ce que vous lisez | Ce que ça veut dire | Ce que vous faites |
|-------------------|---------------------|-------------------|
| **« Cette livraison n'est pas en échec »** | La livraison a abouti, ou elle est encore dans la file : il n’y a rien à rejouer. | Attendez le prochain essai, ou ouvrez la page de la livraison pour voir où elle en est. |
| **« Le point d'entrée de cette livraison est désactivé »** | Le point d’entrée a été coupé. | Réactivez-le sur la page du projet, puis revenez rejouer. |
| **« Livraison introuvable »** | La livraison n’existe pas, ou elle appartient à un projet que vous ne voyez pas. | Vérifiez l’identifiant, et que vous êtes bien sur le bon projet. |
| **« Rejeu mis en file »**, puis plus rien | La nouvelle livraison est dans la file, mais son essai n’a pas encore eu lieu. | Attendez, puis rechargez la liste : la ligne est repassée à **« En attente »**. |
| Le destinataire a bien répondu, mais le Core dit que la livraison a échoué | La réponse du destinataire n’est pas une des réponses que le Core accepte comme un succès. | Lisez-la dans **« Réponse reçue »** et adaptez votre application pour qu’elle réponde correctement. |

## Et ensuite

- Vous voulez **reprendre le branchement depuis le début** : parcourez
  [F14 — Brancher une application cliente](F14-brancher-une-application-cliente.md).
- Vous voulez **relier un numéro de téléphone pour écrire au client** : parcourez
  [F16 — Connecter WhatsApp et envoyer des messages](F16-connecter-whatsapp-et-envoyer-des-messages.md).
