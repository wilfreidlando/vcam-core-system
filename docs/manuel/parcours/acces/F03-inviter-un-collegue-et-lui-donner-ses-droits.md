---
numero: F03
titre: Inviter un collègue et lui donner ses droits
situation: Ajouter quelqu'un à l'équipe
famille: acces
famille_titre: Se connecter et gérer les accès
profil: Opérateur
temps: 5 minutes
---

## Ce qu’il faut savoir avant

- **Un compte s’ouvre par une invitation, jamais par un mot de passe choisi par la
  console.** Vous ne transmettez pas un mot de passe à votre collègue : vous lui
  transmettez une invitation. C’est **lui** qui choisit son mot de passe, au moment
  d’activer son compte.
- **L’invitation est un jeton à usage unique, valable 72 heures, et il n’est montré
  qu’une seule fois.** L’écran qui le porte disparaît dès que vous quittez la page :
  copiez-le avant, et ne comptez pas sur la console pour le retrouver.
- **Un jeton est un secret.** Transmettez-le en direct, à la personne concernée. Ni dans
  un ticket, ni dans un message de groupe, ni dans une capture d’écran.
- **Il y a deux listes de rôles, et elles ne se mélangent pas.** Un rôle de plateforme
  donne ses droits dans la console entière ; un rôle d’organisation ne vaut que dans une
  organisation, et c’est sur la fiche de cette organisation qu’on l’attribue.
- **On ne peut pas se donner plus de droits qu’on n’en a.** Seul un propriétaire
  d’organisation attribue le rôle de propriétaire, et une organisation garde toujours un
  propriétaire.

## Les étapes

### 1. Ouvrez l’écran **« Opérateurs »**

Dans la barre latérale, à la section **« Identité »**, cliquez **« Opérateurs »**. La liste des
personnes qui ont un compte console s’affiche, avec le bouton **« Nouvel opérateur »** en
haut à droite. Cette entrée n’est visible que par un administrateur de plateforme ;
les autres membres de l’équipe passent par l’écran **« Membres »** de l’organisation, à
l’étape 6.

### 2. Renseignez l’e-mail et le rôle

Cliquez **« Nouvel opérateur »**. Le formulaire s’ouvre, intitulé **« Inviter un opérateur »**.
Remplissez le champ **« Email »** avec l’adresse de votre collègue, puis choisissez son rôle
dans la liste **« Rôle »**. Rien n’est sélectionné au départ : c’est à vous de choisir, pour
qu’un rôle trop puissant ne soit pas choisi à votre place.

### 3. Cliquez sur **« Envoyer l’invitation »**

→ **Ce qui se passe** : la console crée l’invitation et vous ramène à la liste des
opérateurs. Un encadré vert, en haut de la page, affiche le jeton d’activation.

### 4. Copiez le jeton et transmettez-le à votre collègue

L’encadré **« Invitation créée : … »** affiche le jeton et la date jusqu’à laquelle il
fonctionne. Copiez le jeton et envoyez-le directement à votre collègue. Si vous rechargez
la page, il a disparu.

### 5. Votre collègue active son compte

Votre collègue ouvre la page **« Activer mon compte »**, colle le jeton dans le champ
**« Jeton d’activation »**, choisit un mot de passe, le répète dans le champ
**« Confirmez le mot de passe »**, puis clique sur **« Activer mon compte »**.

→ **Ce qui se passe** : le compte est ouvert. Votre collègue arrive sur l’écran
**« Configurer un authentificateur »** : il scanne le code affiché, saisit le code à
6 chiffres et clique sur **« Confirmer et se connecter »**. Tant que cette étape n’est pas
faite, le compte n’est pas utilisable.

### 6. Donnez-lui ses droits sur une organisation

Si votre collègue doit travailler sur les projets d’une organisation, ajoutez-le aussi
comme membre. Dans la barre latérale, section **« Identité »**, cliquez **« Organisations »** ;
sur la ligne de l’organisation, cliquez **« Membres »**. Le bouton **« + Inviter un membre »**
mène au même formulaire : e-mail, rôle, puis **« Envoyer l’invitation »**. Le jeton
s’affiche une seule fois aussi.

→ **Ce qui se passe** : au moment d’activer son invitation, votre collègue rejoint
l’organisation avec le rôle choisi. S’il a déjà un compte console, il saisit le mot de
passe qu’il a déjà : son mot de passe n’est ni changé ni remplacé.

### 7. Ajustez un rôle ou retirez quelqu’un

Sur l’écran **« Membres »**, la colonne **« Rôle »** porte une liste déroulante et un bouton
**« Appliquer »** : le changement ne part pas tout seul, il faut le valider. Le bouton
**« Révoquer »** retire la personne de l’organisation ; son compte console, lui, reste actif.

## Ce que vous voyez

| Sur l’écran | Ce que ça veut dire |
|-------------|--------------------|
| **« Inviter un opérateur »** | Vous ouvrez un compte console, vous ne changez pas les droits d’un compte existant. |
| **« Rôle »** | Le rôle de plateforme de votre collègue. La liste ne propose rien par défaut : un rôle choisi à votre place serait un rôle trop puissant. |
| **« Envoyer l’invitation »** | Le bouton qui crée l’invitation. |
| **« Invitation créée : … »** | L’invitation existe. Le jeton affiché juste dessous est la seule occasion de le lire. |
| **« Jeton d’activation à usage unique — affiché uniquement maintenant, jamais stocké ni journalisé »** | Personne, pas même vous, ne pourra retrouver ce jeton après ce message. |
| **« Valable jusqu’au »** | La date et l’heure après lesquelles le jeton ne fonctionne plus. |
| **« Configuré »** / **« Non configuré »** | Si la personne a déjà branché son application d’authentification. |
| **« Actif »** / **« Révoqué »** | Si le compte est utilisable. Un compte révoqué ne peut plus se connecter. |
| **« Membres »** | Le lien qui mène à la fiche des membres d’une organisation. |
| **« + Inviter un membre »** | Le bouton qui donne à quelqu’un des droits dans cette organisation. |
| **« Propriétaire »**, **« Administrateur »**, **« Financier »**, **« Support »**, **« Lecture seule »** | Les cinq rôles d’une organisation. Seul **« Propriétaire »** peut en attribuer un autre. |
| **« Administrateur de plateforme »**, **« Opérateur financier »**, **« Opérateur support »**, **« Responsable des prestataires »**, **« Auditeur (lecture seule) »** | Les cinq rôles de la console. |
| **« Appliquer »** | Le bouton qui enregistre le nouveau rôle d’un membre. |
| **« Révoquer »** | Sur l’écran **« Membres »**, il retire la personne de l’organisation ; sur l’écran **« Opérateurs »**, il ferme le compte console. |

## Si ça ne marche pas

| Ce que vous lisez | Ce que ça veut dire | Ce que vous faites |
|-------------------|---------------------|-------------------|
| Un message vous disant qu’un compte existe déjà pour cette adresse — la console l’affiche en anglais | Cette personne a déjà un compte console. | Ne la réinvitez pas : elle se connecte avec ce qu’elle a déjà. Pour lui donner des droits dans une organisation, passez par **« Membres »** et invitez-la de ce côté. |
| **« Vous êtes déjà membre de cette organisation. »** | Elle y est déjà. | Changez son rôle depuis la liste des membres, au lieu de l’inviter de nouveau. |
| **« Une organisation garde toujours un propriétaire : nommez-en un autre avant de retirer ou de rétrograder celui-ci. »** | Vous êtes en train de laisser une organisation sans propriétaire. | Nommez d’abord un autre propriétaire, puis retirez ou rétrogradez le premier. |
| **« Vous ne pouvez pas attribuer un rôle supérieur au vôtre : seul un propriétaire attribue le rôle de propriétaire. »** | Vous n’avez pas le droit de donner ce rôle-là. | Demandez à un propriétaire de l’organisation de le faire. |
| **« Vous ne pouvez pas révoquer votre propre compte : demandez à un autre administrateur. »** | Vous êtes en train de vous retirer l’accès à vous-même. | Demandez à un autre administrateur de plateforme de le faire. |
| **« La console garde toujours un administrateur de plateforme actif : invitez-en un autre avant de révoquer celui-ci. »** | Il resterait personne pour administrer la console. | Invitez d’abord un autre administrateur de plateforme, puis revenez. |
| **« Cette invitation a expiré (72 heures). Demandez-en une nouvelle. »** | Le jeton est trop vieux. | Créez une nouvelle invitation et transmettez le nouveau jeton. |
| **« Cette invitation a déjà été utilisée : connectez-vous avec votre compte. »** | Le jeton a déjà servi. | La personne se connecte ; si elle n’a pas de compte, il lui faut une nouvelle invitation. |
| **« Aucune invitation ne correspond à ce jeton. »** | Le jeton est faux ou mal recopié. | Recopiez-le en entier, sans espace ni caractère perdu. |
| **« Les deux mots de passe ne sont pas identiques. »** | La confirmation ne colle pas. | Retapez les deux champs. |
| **« Vous avez déjà un compte : saisissez son mot de passe actuel »** | Votre collègue a déjà un compte console : l’invitation ne lui demande pas d’en choisir un nouveau. | Il saisit le mot de passe qu’il utilise déjà, et il rejoint l’organisation. |

## Et ensuite

- Votre collègue veut se connecter tout de suite : parcourez [F01 — Se connecter et
  confirmer mon identité](F01-se-connecter-et-confirmer-mon-identite.md).
- Vous voulez **savoir ce qu’un collègue a fait** : parcourez [F04 — Retrouver qui a
  fait quoi](F04-retrouver-qui-a-fait-quoi.md).
- Vous voulez **retirer quelqu’un** plutôt que lui donner des droits : le bouton
  **« Révoquer »** fait les deux, et le geste reste dans le journal d’audit.
