---
numero: F14
titre: Brancher une application cliente
situation: Brancher une application cliente : clés, livraisons, prestataire
famille: livraisons
famille_titre: Brancher et surveiller les livraisons
profil: Opérateur
temps: 20 minutes
---

## Ce qu’il faut savoir avant

- **Brancher une application cliente, ce sont trois gestes distincts.** Une
  **clé** — le droit d’appeler le Core ; un **point d’entrée** — l’adresse où le
  Core vient livrer les événements ; une **application** — le compte du projet
  chez le service qui encaisse les paiements. Aucun des trois n’est obligatoire
  pour les deux autres : faites seulement ceux dont vous avez besoin.
- **Un secret ne se montre qu’une fois.** La clé et le secret de signature sont
  affichés à l’instant où vous les créez, et jamais plus. Perdu, ils ne se
  relisent pas : il faut refaire la clé, ou faire tourner le secret.
- **Une adresse de réception ne peut pas être enregistrée deux fois** pour un
  même projet : le même événement y serait livré en double.
- **Pendant la rotation, l’ancien secret continue de signer.** C’est ce qui évite
  que votre application cesse de recevoir les événements pendant que vous la
  redéployez.

## Les étapes

### 1. Ouvrir la fiche du projet

Dans la barre latérale, **« Projets »**, puis **« Voir → »** en face du projet.
La fiche est une page de liens : chacun ouvre une rubrique du projet.

→ **Ce qui se passe** : la fiche s’ouvre, avec le nom du projet, son
organisation et son statut, et une liste de liens — dont **« Clés API »**,
**« Enregistrer un point d'entrée pour les événements »** et **« Sessions WhatsApp »**.

### 2. Créer une clé pour votre application

Cliquez sur **« Clés API »**. Dans le formulaire **« Nouvelle clé »**, donnez un
**« Nom »** qui dit à quoi elle sert, choisissez les droits accordés, puis
cliquez sur **« Créer la clé »**.

Les droits se choisissent par modèle — **« Serveur complet »**,
**« Lecture seule »**, **« Portail uniquement »**, **« Application cliente »**,
**« Versements »** — ou un par un, avec **« Personnalisée »**.

→ **Ce qui se passe** : la clé est créée et s’affiche une seule fois, dans un
encadré **« Nouvelle clé »**. Elle ne sera plus jamais montrée.

### 3. Copier la clé, puis quitter la page

Recopiez la suite affichée dans votre gestionnaire de mots de passe, avec le nom
du projet auquel elle appartient. Cette suite donne le droit d’appeler le Core au
nom du projet : ne la transmettez pas dans un message ordinaire.

→ **Ce qui se passe** : au rechargement de la page, la clé a disparu de
l’écran. Restent son nom, son préfixe, ses droits et la date de sa dernière
utilisation.

### 4. Enregistrer le point d’entrée de votre application

Sur la fiche du projet, cliquez sur **« Enregistrer un point d'entrée pour les événements »**. Le
formulaire **« Enregistrer le point d'entrée d'une application cliente »**
s’ouvre.

- **« URL de réception »** — l’adresse de votre application qui reçoit les
  événements. Elle doit être joignable depuis l’extérieur.
- **« Sur quoi voulez-vous être notifié ? »** — les événements que vous voulez
  recevoir. Ceux du commerce du projet (clients, abonnements, factures, quotas)
  sont déjà cochés ; **« Tout recevoir »** prend tout.

Cliquez sur **« Enregistrer le point d'entrée »**.

→ **Ce qui se passe** : le point d’entrée est enregistré et un encadré
**« Point d'entrée enregistré »** s’affiche, avec le **« Secret de signature »** juste
en dessous. Ce secret, lui, ne sera plus jamais affiché.

### 5. Remettre le secret de signature à votre application

Recopiez le secret affiché et transmettez-le à votre application **en dehors du
Core** : un gestionnaire de mots de passe partagé, une variable d’environnement
sur son serveur. Il lui sert à vérifier que la livraison vient bien du Core, et
pas d’un tiers.

→ **Ce qui se passe** : le secret ne peut plus être relu. Si vous le perdez,
faites tourner le secret — c’est l’étape 8.

### 6. Tester la liaison

Dans la liste des points d’entrée, cliquez sur **« Tester »** en face de
l’adresse.

→ **Ce qui se passe** : le Core envoie un événement d’essai à votre adresse et
affiche la réponse du destinataire :

- **« Le destinataire a bien reçu l’événement de test. »** — l’adresse a
  accepté l’événement d’essai ;
- **« Le destinataire n’a pas accepté l’événement de test. »** — l’adresse a
  répondu, mais pas comme il faut.

### 7. Enregistrer l’application chez le service qui encaisse

Sur la fiche du projet, ouvrez la page **« Applications prestataire »** : c’est
le compte que ce projet a chez le service qui encaisse les paiements. Remplissez
**« Prestataire »**, **« Libellé »**, **« Clé publique »** et **« Clé privée »**,
puis cliquez sur **« Enregistrer l'application »**.

La clé privée n’est jamais réaffichée : gardez-la dans votre gestionnaire de
mots de passe.

→ **Ce qui se passe** : l’application est enregistrée et apparaît dans le
tableau **« Applications enregistrées »**. Les paiements du projet passent
désormais par ce compte.

### 8. Faire tourner le secret quand l’application change

Dans la liste des points d’entrée, cliquez sur **« Faire tourner le secret »**,
dites combien d’heures l’ancien secret doit continuer de signer
(**« Recouvrement (heures) »**), puis confirmez avec
**« Générer le nouveau secret »**.

→ **Ce qui se passe** : un **« Nouveau secret de signature »** s’affiche, une
seule fois. Tant que le recouvrement court, un bandeau
**« Double signature active »** rappelle que l’ancien secret signe encore : le
destinataire accepte les deux signatures et ne casse pas. Déployez la nouvelle
version de votre
application avant la fin du recouvrement ; ensuite,
**« Révoquer l'ancien secret »** — avec un **« Motif (journalisé) »** — coupe
immédiatement l’ancien signataire.

## Ce que vous voyez

| Sur l’écran | Ce que ça veut dire |
|-------------|--------------------|
| **« Clés du projet »** | La liste des clés du projet : nom, préfixe, droits accordés, dernière utilisation. |
| Les pastilles d’une clé | **« Active »** : la clé fonctionne. **« Expirée »** : elle ne fonctionne plus. **« Remplacée »** : une clé plus récente a pris sa place, mais l’ancienne fonctionne encore jusqu’à la date affichée. **« Active · expire le »** : la clé fonctionne jusqu’à la date affichée. |
| **« Accès total »** | Cette clé peut tout faire sur le projet. Préférez des droits précis. |
| **« Point d'entrée enregistré »** | Le point d’entrée vient d’être créé. Le secret de signature est dans le même encadré, et n’apparaîtra plus. |
| **« Double signature active »** | L’ancien secret signe encore, jusqu’à la date affichée. C’est ce qui laisse le temps à votre application de basculer. |
| **« Applications enregistrées »** | Les comptes du projet chez les services qui encaissent. |
| **« Prestataire »** | Le service qui encaisse les paiements pour ce projet. |
| **« Clé publique »** | La clé publique du compte, montrée tronquée. La clé privée, elle, n’est jamais réaffichée. |

## Si ça ne marche pas

| Ce que vous lisez | Ce que ça veut dire | Ce que vous faites |
|-------------------|---------------------|-------------------|
| **« Choisissez au moins un événement »** | Vous n’avez coché aucun événement, et pas davantage **« Tout recevoir »**. | Cochez au moins un événement, ou cochez **« Tout recevoir »**, puis enregistrez. |
| **« Cette URL reçoit déjà les événements de ce projet »** | Un autre point d’entrée du même projet reçoit déjà à cette adresse : le même événement y serait livré deux fois. | Ouvrez le point d’entrée existant et changez son adresse ou ses événements, au lieu d’en enregistrer un second. |
| **« Cette URL est déjà enregistrée pour ce projet »** | L’adresse existe déjà, mais son point d’entrée est désactivé. | Réactivez-le avec **« Réactiver »**, au lieu d’enregistrer un second. |
| **« Ce projet atteint déjà son plafond de »** | Le projet a déjà assez de points d’entrée actifs : le nouveau serait refusé. | Désactivez-en un avec **« Désactiver »**, puis enregistrez le nouveau. |
| **« Le destinataire n’a pas accepté l’événement de test. »** | L’adresse a répondu, mais pas comme il faut. La réponse du destinataire est affichée juste au-dessus. | Lisez-la. Si elle dit que la signature ne correspond pas, vérifiez que votre application utilise bien le secret affiché. |
| Le secret affiché ne réapparaît plus quand vous revenez sur la page | Il n’a été montré qu’une fois, à la création. | Faites tourner le secret : un nouveau secret s’affiche, et l’ancien continue de signer pendant le recouvrement. |

## Et ensuite

- Vous voulez **savoir ce qui a été livré, et pourquoi une livraison a échoué** :
  parcourez [F15 — Diagnostiquer un échec de livraison](F15-diagnostiquer-un-echec-de-livraison.md).
- Vous voulez **relier un numéro de téléphone pour écrire au client** : parcourez
  [F16 — Connecter WhatsApp et envoyer des messages](F16-connecter-whatsapp-et-envoyer-des-messages.md).
