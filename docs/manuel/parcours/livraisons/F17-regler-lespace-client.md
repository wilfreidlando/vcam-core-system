---
numero: F17
titre: Régler l'espace client
situation: Régler le portail du client
famille: livraisons
famille_titre: Brancher et surveiller les livraisons
profil: Commerce
temps: 10 minutes
---

## Ce qu’il faut savoir avant

- **L’espace client se règle projet par projet.** Chaque projet a son propre
  portail, sa propre couleur, ses propres droits.
- **Le nom affiché est obligatoire.** C’est le nom que le client voit en haut de
  son espace.
- **Vous ne créez pas de compte client.** Vous réglez ; le client s’identifie,
  soit par un code reçu par WhatsApp, soit parce que votre application lui a
  ouvert son espace.
- **La connexion par code est désactivée par défaut.** Elle n’ouvre que le
  portail, jamais votre application.
- **Rien de coché dans les moyens de paiement = tous les canaux.** Le portail
  n’a alors aucun service de paiement à configurer : le paiement passe par les
  canaux de la plateforme.

## Les étapes

### 1. Ouvrir la page du portail

Sur la fiche du projet, **« Portail client »**.

→ **Ce qui se passe** : la page s’ouvre sur le bandeau
**« L'espace de vos clients, à vos couleurs »**, puis sur le formulaire de
réglage.

### 2. Donner un nom, une couleur, un logo et une adresse d’aide

- **« Nom affiché »** — obligatoire. C’est le nom que le client voit en haut de
  son espace.
- **« Couleur de la marque »** — la couleur du portail, écrite comme un code à
  six chiffres précédé d’un `#`. Un aperçu à côté du champ montre le rendu.
- **« Logo (URL https) »** — l’adresse de votre logo.
- **« Aide (URL https ou mailto:) »** — où le client trouve de l’aide : une page
  web, ou une adresse de courriel.
- **« Langue »** — facultatif ; le portail est en français si vous la laissez
  vide.

### 3. Autoriser les adresses de retour

Dans **« Adresses de retour autorisées »**, **une URL par ligne** : l’origine exacte,
avec un préfixe de chemin facultatif. Ce sont les seules adresses vers
lesquelles un client peut être renvoyé après un paiement ; toute autre adresse
de retour est refusée.

### 4. Choisir ce que le client peut faire seul

Dans le cadre **« Ce qu'un client peut faire seul »**, cochez les gestes que le
client doit pouvoir faire sans vous appeler :

- **« Souscrire, changer d’offre et renouveler »** ;
- **« Résilier à la fin de la période (et reprendre le renouvellement) »**.

### 5. Proposer la connexion par code (facultatif)

Dans le cadre **« Connexion directe par code »** — la mention
**« (désactivée par défaut) »** est là pour le rappeler — cochez
**« Proposer la connexion par code »** et indiquez, dans
**« Session WhatsApp d'envoi »**, le nom d’une session de ce projet. C’est
elle qui enverra le code.

→ **Ce qui se passe** : le client peut alors entrer seul dans son espace. Sur
la page **« Accéder à votre espace client »**, il saisit son
**« Numéro de téléphone »** et clique sur **« Recevoir mon code »**. Le code à
6 chiffres arrive par WhatsApp ; sur la page **« Saisissez votre code »**, il le
saisit dans **« Code à 6 chiffres »** et valide avec **« Se connecter »**.

### 6. Restreindre les moyens de paiement (facultatif)

Dans le cadre **« Restreindre les moyens de paiement »**, cochez les canaux
proposés au client : **« MTN Mobile Money »**, **« Orange Money »**,
**« Carte bancaire »**. Rien de coché : tous les canaux que la plateforme sait
traiter pour ce projet.

### 7. Enregistrer les réglages

Cliquez sur **« Enregistrer »**.

→ **Ce qui se passe** : les réglages sont enregistrés, et un bandeau confirme
**« Réglages du portail enregistrés. »**

### 8. Voir ce que le client voit

Ouvrez l’espace client — par la connexion par code, ou depuis votre
application. Le client arrive sur une page qui l’accueille par son nom
(**« Bonjour »**) et lui montre d’un coup d’œil :

- ce qu’il doit : **« À régler »** quand une facture attend, **« Solde »** —
  et **« À jour »** — quand il ne doit rien ;
- **« Votre abonnement »**, avec sa date de fin et le bouton **« Gérer »** ;
- son **« Utilisation »**, quand son offre comporte des quotas ;
- ses **« Factures à régler »**, puis **« Activité récente »** et
  **« Toutes les factures »**.

→ **Ce qui se passe** : le portail se présente exactement comme vous l’avez
réglé : même nom, même couleur, mêmes droits.

## Ce que vous voyez

| Sur l’écran | Ce que ça veut dire |
|-------------|--------------------|
| **« L'espace de vos clients, à vos couleurs »** | Le bandeau d’explication : le portail sert au suivi de l’abonnement, au paiement des factures et à la consultation de l’usage. |
| **« Nom affiché »** | Le nom que le client voit en haut de son espace. |
| **« Couleur de la marque »** | La couleur du portail. Un aperçu à côté du champ montre le rendu. |
| **« Adresses de retour autorisées »** | Les seules adresses vers lesquelles un client peut être renvoyé après un paiement. |
| **« Ce qu'un client peut faire seul »** | Les deux gestes que le client peut faire sans vous appeler : changer d’offre et renouveler, ou résilier à la fin de sa période. |
| **« Proposer la connexion par code »** | Le client peut entrer seul dans son espace avec un code à 6 chiffres reçu par WhatsApp. |
| **« Session WhatsApp d'envoi »** | La session qui envoie ce code. Sa valeur est le nom d’une session du projet : elle doit exister. |
| **« Restreindre les moyens de paiement »** | Les canaux proposés au client. Rien de coché : tous ceux que la plateforme sait traiter. |

## Si ça ne marche pas

| Ce que vous lisez | Ce que ça veut dire | Ce que vous faites |
|-------------------|---------------------|-------------------|
| Un bandeau rouge après **« Enregistrer »** | Un réglage a été refusé. | Corrigez le champ signalé, puis enregistrez à nouveau. |
| Le nom affiché ou la couleur est refusé | Le nom est obligatoire et ne peut pas dépasser 80 caractères ; la couleur doit être écrite comme un code à six chiffres précédé d’un `#`. | Corrigez la valeur, puis enregistrez. |
| Le logo est refusé, alors que son adresse est bien en https | Le projet doit déclarer d’abord les sites qu’il autorise ; tant qu’aucun n’est déclaré, aucune adresse externe n’est acceptée. | Faites déclarer les sites autorisés par votre application, ou retirez le logo. |
| L’adresse d’aide est refusée | Soit elle n’est ni en https ni une adresse de courriel, soit son site n’est pas encore autorisé. | Mettez une adresse de courriel, ou faites autoriser le site par votre application. |
| La connexion par code reste inactive alors que vous l’avez cochée | Il manque la session qui doit envoyer le code. | Renseignez **« Session WhatsApp d'envoi »** avec le nom d’une session existante du projet. |
| Une adresse de retour est refusée | Elle n’est pas en https, ou elle porte autre chose que l’origine et un chemin. | Gardez l’origine seule, éventuellement suivie d’un chemin. |
| Le client est renvoyé ailleurs après son paiement | Son adresse de retour n’est pas dans la liste. | Ajoutez-la dans **« Adresses de retour autorisées »**, une URL par ligne. |

## Et ensuite

- Vous voulez **relier un numéro de téléphone pour écrire au client** : parcourez
  [F16 — Connecter WhatsApp et envoyer des messages](F16-connecter-whatsapp-et-envoyer-des-messages.md).
- Vous voulez **savoir si un événement est bien arrivé chez le client** :
  parcourez [F15 — Diagnostiquer un échec de livraison](F15-diagnostiquer-un-echec-de-livraison.md).
