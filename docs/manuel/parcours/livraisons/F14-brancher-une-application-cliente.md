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
- **L’application peut annoncer la forme de ses clients.** Elle propose alors
  **la forme** — la clé unique qu’elle utilise, le format de ses références, les
  champs qu’elle attend — et **vous** la lisez et la décidez. Le Core ne
  suppose rien de votre métier. Depuis la version 1.7 du paquet, **c’est le
  Core qui va la lui demander** : vous n’avez ni commande à taper, ni fichier à
  fabriquer.
- **Si votre application avait déjà des clients, vous les faites connaître au
  Core.** Le Core les crée, les pousse ; votre application, qui les connaît
  déjà, les **relie** au lieu d’en créer un second. Aucun doublique possible.
  Là encore, **c’est le Core qui vient les chercher**.

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

La même clé publique peut être enregistrée pour plusieurs projets : c’est le
cas quand la plateforme encaisse pour plusieurs entités avec un seul compte
chez le service qui encaisse. Chaque retour du prestataire est alors rattaché
au projet du paiement qu’il annonce, jamais à la clé seule.

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

### 9. Faire connaître au Core la forme que l'application veut

L'application sait ce qu'elle attend du Core : la clé unique qu'elle utilise, le
format que ses références doivent respecter, les champs qu'elle veut sur ses
clients. **Le Core va le lui demander** — il n'y a rien à recopier, rien à
inventer.

Sur la page **« Clients »** du projet, cliquez sur **« Application cliente »**.
La page se lit en quatre temps :

- **① L'application reçoit-elle les événements ?** — le point d'entrée
  enregistré pour ce projet ;
- **② L'application parle-t-elle au Core ?** — l'adresse que le Core va
  appeler, **déduite du point d'entrée** (même hôte). Vous pouvez la corriger
  si l'API de votre application n'est pas sur le même hôte que la réception
  des événements ;
- **③ La forme du client** — c'est là que se trouve le bouton
  **« Récupérer la forme de l'application »** ;
- **④ Les clients de l'application** — le bouton
  **« Créer les clients depuis l'application »**.

Cliquez sur **« Récupérer la forme de l'application »**.

→ **Ce qui se passe** : le Core interroge votre application, **valide** ce
qu'elle annonce, et l'enregistre comme une **proposition en attente**. Rien
n'est changé tant que vous ne l'avez pas acceptée.

**Pour l'accepter**, la page *« Forme du client »* (où le lien **« Lisez-la »**
vous mène) se lit comme une liste, pas comme un fichier technique :

- **« La référence »** — le format annoncé, écrit tel quel et expliqué en mots
  (« AE suivi de 4 à 8 chiffres »), avec le numéro de version de la proposition.
- **« Les champs annoncés »** — un tableau : **« Champ »**, **« Type »**,
  **« Obligatoire »**, **« Origine »**, **« Longueur maximale »**.
- L’**« Origine »** dit d’où vient la valeur : *« le nom du client »*,
  *« le contact du client »*, *« une valeur déclarée par l'application »*. Une
  origine qui reprend un champ du Core **remplace** ce champ : vous ne saisissez
  jamais la même valeur deux fois.

Cliquez sur **« Accepter la déclaration »** ou **« Refuser la déclaration »**.

→ **Ce qui se passe** : la forme acceptée entre en vigueur. Le formulaire
**« Nouveau client »** affiche désormais un champ par clé annoncée, et le
format de la référence est écrit sous le champ. Une forme refusée laisse la
précédente en vigueur — rien ne change.

> Les valeurs que vous saisissez dans ces champs sont conservées **en clair**,
> comme le nom du client. Elles ne doivent donc pas porter de donnée
> personnelle : ni pièce d’identité, ni adresse, ni numéro de téléphone.

### 10. Faire connaître au Core les clients que l'application avait déjà

Si votre application avait déjà ses clients avant le branchement, ils ne sont
pas chez le Core. **Le Core va les chercher** — toujours depuis la page
**« Application cliente »**, en **④ Les clients de l'application**.

Cliquez sur **« Créer les clients depuis l'application »**.

→ **Ce qui se passe** : le Core interroge votre application, qui lui rend la
liste de ses clients **avec les valeurs qu'elle connaît déjà** (la
localisation, le nom du responsable, l'année de création), et affiche un
**aperçu** :

- une ligne de résumé : combien de clients l'application a, combien seront
  créés, combien sont déjà connus, combien seront refusés ;
- un tableau **« Clients refusés »** : **« Ligne »**, **« Référence »**,
  **« Motif »** — une référence qui ne suit pas le format en vigueur, un champ
  annoncé qui manque, une ligne illisible.

Cliquez sur **« Créer les clients »**.

→ **Ce qui se passe** : le Core crée les clients et les pousse vers votre
application, qui les **relie** — elle en connaît déjà, elle n’en crée pas de
second. Le **« Rapport »** dit ce qui a été créé, ce qui était déjà connu, et
**pour chaque client refusé, sa raison**. Un client refusé n’est jamais créé.

**Relancer la même chose ne crée pas de doublique** : la référence est unique
chez le Core. Un client déjà connu est mis à jour, jamais dupliqué.

> **Le chemin de secours.** Quand l'application est injoignable, ou pour un lot
> hors bande, l'import de fichiers reste disponible : sur la page
> **« Clients »**, **« Importer des clients »**. Le fichier doit porter les
> colonnes du modèle, dans cet ordre : **« Référence »**, **« Nom »**,
> **« E-mail »** et **« Téléphone »** — et, si vous avez annoncé des champs
> pour vos clients, **une colonne par champ annoncé**, à la place des colonnes
> que ces champs remplacent. La page **« Import de clients »** vous donne le
> fichier tout fait : le bloc **« Le modèle du fichier »** explique chaque
> colonne, et **« Télécharger le modèle »** vous donne un fichier qui porte
> l’en-tête et une ligne d’exemple — il s’importe tel quel.
>
> C’est un chemin de secours, pas le parcours ordinaire : la console sait
> fabriquer ce fichier, et l'application sait le remplir
> ([ADR-0133](../../../adr/0133-la-console-tire-la-forme-et-les-clients-de-l-application.md)).

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
| **« Rien à décider pour l'instant »** | Aucune application n’a proposé de forme pour ce projet : le formulaire **« Nouveau client »** ne demande que l’identité du Core. |
| **« Application cliente »** | La page d'état du branchement : ce que le Core sait de votre application, l'adresse qu'il lui appelle, la forme du client, ses clients. |
| **« Récupérer la forme de l'application »** | Le Core interroge votre application et enregistre ce qu'elle annonce comme une proposition **en attente**. |
| **« Créer les clients depuis l'application »** | Le Core vient chercher la liste de vos clients et affiche l'aperçu de ce qu'il en ferait. |
| **« Aperçu »** | Ce que le Core ferait de la liste tirée de l'application : combien à créer, combien déjà connus, combien refusés. |
| **« Rapport »** | Ce que le Core a fait : les clients créés, ceux qui étaient déjà connus, et les refus — avec leur motif. |
| **« Les champs annoncés »** | Les champs que l’application veut sur ses clients. Une ligne = un champ, avec son type, son caractère obligatoire, son origine et sa longueur maximale. |
| **« L'application a proposé une forme pour ses clients »** | Une proposition attend votre décision. Elle ne change rien tant que vous ne l’avez pas acceptée. |
| **« Rapport de l'import »** | Ce que l’import a fait : les clients créés, ceux qui étaient déjà connus, et les lignes refusées avec leur motif. |
| **« Clients déjà connus »** | Ces clients existaient déjà chez le Core : ils ont été mis à jour, jamais dupliqués. |
| **« Le modèle du fichier »** | Le fichier à déposer doit porter les colonnes du modèle, dans l’ordre. Le bloc les explique une par une. |
| **« Télécharger le modèle »** | Un fichier prêt à remplir : il porte l’en-tête et une ligne d’exemple. Il s’importe tel quel. |

## Si ça ne marche pas

| Ce que vous lisez | Ce que ça veut dire | Ce que vous faites |
|-------------------|---------------------|-------------------|
| **« Choisissez au moins un événement »** | Vous n’avez coché aucun événement, et pas davantage **« Tout recevoir »**. | Cochez au moins un événement, ou cochez **« Tout recevoir »**, puis enregistrez. |
| **« Cette URL reçoit déjà les événements de ce projet »** | Un autre point d’entrée du même projet reçoit déjà à cette adresse : le même événement y serait livré deux fois. | Ouvrez le point d’entrée existant et changez son adresse ou ses événements, au lieu d’en enregistrer un second. |
| **« Cette URL est déjà enregistrée pour ce projet »** | L’adresse existe déjà, mais son point d’entrée est désactivé. | Réactivez-le avec **« Réactiver »**, au lieu d’enregistrer un second. |
| **« Ce projet atteint déjà son plafond de »** | Le projet a déjà assez de points d’entrée actifs : le nouveau serait refusé. | Désactivez-en un avec **« Désactiver »**, puis enregistrez le nouveau. |
| **« Le destinataire n’a pas accepté l’événement de test. »** | L’adresse a répondu, mais pas comme il faut. La réponse du destinataire est affichée juste au-dessus. | Lisez-la. Si elle dit que la signature ne correspond pas, vérifiez que votre application utilise bien le secret affiché. |
| Le secret affiché ne réapparaît plus quand vous revenez sur la page | Il n’a été montré qu’une fois, à la création. | Faites tourner le secret : un nouveau secret s’affiche, et l’ancien continue de signer pendant le recouvrement. |
| **« Cette déclaration n’attend plus de lecture : elle a déjà été décidée. »** | Quelqu’un a déjà accepté ou refusé cette proposition pendant que vous lisiez. | Rechargez la page : la forme en vigueur est celle qui s’affiche. |
| Une ligne du rapport d’import est refusée | Sa référence ne suit pas le format en vigueur, un champ annoncé manque, ou la ligne est illisible. | Corrigez la ligne dans le fichier et réimportez. Une ligne refusée n’a rien créé. |
| Le Core n’arrive pas à joindre l’application | L’adresse déduite du point d’entrée n’est pas celle de votre API, ou l’application refuse les lectures signées. | Corrigez l’**« Adresse de l’application »** (②) et vérifiez que le secret du point d’entrée est bien celui que l’application utilise. |
| L’application refuse la lecture du Core | Sa signature ne correspond pas : l’application ne connaît pas le secret du point d’entrée. | Remettez-lui le même secret que celui des événements. |
| Un client est refusé dans l’aperçu | Sa référence ne suit pas le format en vigueur, ou un champ annoncé manque. | Corrigez la référence côté application — ou acceptez d’abord la forme proposée. |
| Le client importé n’apparaît pas dans la liste | Il a été refusé — lisez le **« Rapport de l’import »**, qui donne le motif ligne par ligne. | Corrigez la cause (le format de la référence, le format du fichier) et réimportez. |
| Le formulaire **« Nouveau client »** ne montre aucun champ annoncé | Aucune forme n’a été acceptée pour ce projet. | Lisez **« Forme du client »** et acceptez la proposition de l’application. |

## Et ensuite

- Vous voulez **savoir ce qui a été livré, et pourquoi une livraison a échoué** :
  parcourez [F15 — Diagnostiquer un échec de livraison](F15-diagnostiquer-un-echec-de-livraison.md).
- Vous voulez **relier un numéro de téléphone pour écrire au client** : parcourez
  [F16 — Connecter WhatsApp et envoyer des messages](F16-connecter-whatsapp-et-envoyer-des-messages.md).
