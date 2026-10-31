---
numero: F16
titre: Connecter WhatsApp et envoyer des messages
situation: Envoyer un message au client
famille: livraisons
famille_titre: Brancher et surveiller les livraisons
profil: Opérateur
temps: 10 minutes
---

## Ce qu’il faut savoir avant

- **Le message n’est pas envoyé depuis la console.** Votre application l’envoie au
  Core, qui le fait partir par la session que vous avez préparée. La console
  sert à **préparer la liaison** et à **vérifier le résultat**.
- **Aucun envoi WhatsApp ne part sans une session enregistrée.** Une session,
  c’est un nom et un numéro de téléphone relié, pour un projet.
- **Une session créée n’est pas encore utilisable.** Tant que le téléphone n’est
  pas lié, l’envoi est refusé.
- **Une session de la plateforme sert à tous les projets ; une session de projet,
  à ce seul projet.**
- **Un client ne crée jamais de session : c’est vous qui la créez.**

## Les étapes

### 1. Ouvrir la page des sessions

Sur la fiche du projet, **« Sessions WhatsApp »**. Pour les sessions de la
plateforme, prenez **« Sessions WhatsApp »** dans la barre latérale : le
sous-titre de la page est alors **« Plateforme — sessions globales »**.

→ **Ce qui se passe** : la page **« Sessions enregistrées »** s’ouvre, avec la
liste des sessions et, en dessous, le formulaire **« Créer une session »**.

### 2. Créer la session

Dans **« Nom de la session »**, donnez un nom court, sans espace : c’est ce nom
que votre application utilisera pour envoyer. Cliquez sur **« Créer et démarrer »**.

→ **Ce qui se passe** : la session est créée et démarrée du côté du service de
messagerie, puis enregistrée ici. Un bandeau vous dit de lier le téléphone. La
session reste inutilisable pour l’envoi tant que ce n’est pas fait.

### 3. Lier le téléphone

Deux manières, au choix :

- **« Scanner (QR) »** — la page affiche un QR code. Sur le téléphone, ouvrez la
  rubrique des appareils connectés de WhatsApp et scannez-le. L’image se périme :
  **« Actualisez la page pour obtenir une nouvelle image. »**
- **« Code d'appairage »** — saisissez le numéro du téléphone dans le champ
  **« Numéro de téléphone à lier à la session »**, cliquez sur
  **« Code d'appairage »**, puis saisissez le code affiché dans WhatsApp, à
  **« Associer avec le numéro de téléphone »**.

**« Capture d'écran »** montre l’écran du moteur de messagerie : utile pour voir
ce qu’il attend.

→ **Ce qui se passe** : le téléphone est associé à la session. Elle peut envoyer.

### 4. Vérifier que le téléphone est bien lié

Cliquez sur **« Statut »** en face de la session.

→ **Ce qui se passe** : la console interroge le service de messagerie et vous
dit dans quel état est la session. Si tout va bien :
**« un téléphone est lié, la session peut envoyer. »** Sinon, le message vous
dit ce qui manque — par exemple qu’elle est encore
**« démarrée, en attente de liaison »**, ou **« arrêtée chez le fournisseur »**.

### 5. Vérifier que le message est parti

Sur la fiche du projet, **« Notifications de ce projet »**. La liste donne la
date, le **« Canal »**, le **« Type »**, le **« Destinataire »**, le
**« Statut »** et le **« Prestataire »** de chaque envoi. Cliquez sur
**« Détail → »** pour ouvrir la fiche d’un envoi.

→ **Ce qui se passe** : vous voyez **« Contenu envoyé »**, les **« Variables »**
(dont le numéro du destinataire), et les **« Tentatives d'envoi »** : quel
service a été sollicité, ce qu’il a répondu, et l’**« Erreur »** s’il y en a une.

## Ce que vous voyez

| Sur l’écran | Ce que ça veut dire |
|-------------|--------------------|
| **« Sessions enregistrées »** | Les sessions de ce projet — ou, sur la page de la plateforme, celles que tous les projets peuvent utiliser. |
| **« Créer et démarrer »** | La session est créée *et* démarrée tout de suite : sans cela, il n’y aurait rien à lier. |
| **« Scanner (QR) »** | Le lien du téléphone par un QR code. |
| **« Code d'appairage »** | Le lien du téléphone par un code, quand on ne peut pas scanner. |
| **« Capture d'écran »** | L’écran du moteur de messagerie. Elle n’existe que si le moteur est basé sur un navigateur. |
| **« Statut »** | L’état réel de la session, lu chez le service de messagerie — la seule source fiable pour savoir si un téléphone est lié. |
| Le filtre **« Statut »** de la liste des envois | Les états possibles d’un envoi : **« En cours »**, **« Envoyé »**, **« Livré »**, **« Lu »** et **« Échec »**. |
| **« Tentatives d'envoi »** | Chaque service sollicité pour l’envoi, dans l’ordre, avec son résultat. |
| **« Prestataire »** | Le service qui a envoyé le message. |

## Si ça ne marche pas

| Ce que vous lisez | Ce que ça veut dire | Ce que vous faites |
|-------------------|---------------------|-------------------|
| **« Le fournisseur WhatsApp a refusé la création »** | Le service de messagerie n’a pas accepté de créer la session. | Relancez la création avec un autre nom de session. |
| Le statut dit **« démarrée, en attente de liaison »** | La session existe, mais aucun téléphone n’y est relié. | Revenez sur **« Scanner (QR) »**, ou demandez un **« Code d'appairage »**. |
| Le statut dit **« arrêtée chez le fournisseur »** | La session a été arrêtée du côté du service de messagerie. | Supprimez-la et créez-en une nouvelle. |
| Le statut dit **« en échec chez le fournisseur »** | Le service de messagerie a rencontré une erreur sur cette session. | Supprimez-la et créez-en une nouvelle. |
| Le statut dit **« enregistrée dans le Core mais inconnue du fournisseur »** | La session a été supprimée chez le service de messagerie sans que la console le sache. | Supprimez-la et créez-en une nouvelle. |
| Un envoi est refusé avant de partir, parce que le nom de session ne correspond à aucune session enregistrée | Votre application envoie un nom de session que la console ne connaît pas. | Vérifiez le nom dans **« Sessions enregistrées »** et faites-le correspondre. |
| **« plus aucun envoi possible avec elle. »** après une suppression | La session est supprimée : le téléphone est déconnecté et plus rien ne part par elle. | Créez-en une nouvelle et liez le téléphone. |

## Et ensuite

- Vous voulez **régler l’espace où le client se connecte** : parcourez
  [F17 — Régler l'espace client](F17-regler-lespace-client.md).
- Vous voulez **surveiller ce qui part vers vos applications** : parcourez
  [F15 — Diagnostiquer un échec de livraison](F15-diagnostiquer-un-echec-de-livraison.md).
