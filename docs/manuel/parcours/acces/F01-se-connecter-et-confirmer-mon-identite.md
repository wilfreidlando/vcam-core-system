---
numero: F01
titre: Se connecter et confirmer mon identité
situation: Ouvrir la console le matin, ou reprendre après une coupure
famille: acces
famille_titre: Se connecter et gérer les accès
profil: Tout opérateur
temps: 2 minutes
---

## Ce qu'il faut savoir avant

- Votre compte est **un e-mail et un mot de passe**. Il vous a été donné par la personne
  qui vous a invité ; le Core ne l'invente pas, ne le redemande pas, ne l'envoie pas par
  e-mail.
- **Chaque connexion demande une seconde preuve** : le code à 6 chiffres de votre
  application d'authentification. C'est ce qui distingue une personne d'un programme
  qui devinerait votre mot de passe.
- Le code **change toutes les 30 secondes**. Si vous le saisissez trop tard, on vous
  demande d'en saisir un autre — ce n'est pas une erreur, c'est le temps qui passe.

## Les étapes

### 1. Ouvrez la page de connexion

Rendez-vous sur l'adresse de la console. Vous voyez le titre **« Connexion »**, deux
champs — **« Email »** et **« Mot de passe »** — et le bouton **« Continuer »**.

![La page de connexion : un champ « Email », un champ « Mot de passe », le bouton « Continuer ».](images/F01-01-connexion.png)

### 2. Saisissez votre e-mail et votre mot de passe

Remplissez les deux champs et cliquez sur **« Continuer »**.

→ **Ce qui se passe** : le Core vérifie le mot de passe. S'il est juste, il passe à
l'étape suivante. S'il est faux, un message rouge s'affiche en haut du formulaire et
rien ne se passe d'autre.

### 3. Confirmez votre identité

À votre **première connexion**, la console vous demande de **configurer votre
authentificateur** : elle affiche un **secret** à saisir à la main dans votre
application d'authentification, puis un code à 6 chiffres pour confirmer que tout
fonctionne.

![La page de configuration de l'authentificateur : le secret à saisir et un champ pour le code.](images/F01-02-authentificateur.png)

→ **Ce qui se passe** : le secret affiché ici n'est montré **qu'une seule fois**. Si
vous le perdez, un administrateur doit vous en donner un nouveau. Notez-le dans votre
gestionnaire de mots de passe, avec le compte de la console.

Aux **connexions suivantes**, la console demande simplement le **code à 6 chiffres**
actuel, sous le titre **« Vérifiez votre authentificateur »**.

![La page de vérification : un champ « Code » et le bouton « Vérifier et se connecter ».](images/F01-03-code.png)

### 4. Entrez dans la console

Saisissez le code, cliquez sur **« Vérifier et se connecter »**. Le tableau de bord
s'ouvre.

→ **Ce qui se passe** : la console vous reconnaît pour cette session. Vous n'aurez
plus à saisir de code pendant cinq minutes.

## Ce que vous voyez

| Sur l'écran | Ce que ça veut dire |
|-------------|--------------------|
| **« Connexion »**, avec le sous-titre « Authentifiez-vous pour accéder à la console opérateur. » | Vous n'êtes pas encore connecté. |
| **« Configurer un authentificateur »** | C'est votre première connexion : votre compte n'a pas encore de second moyen de preuve. |
| **« Vérifiez votre authentificateur »** | Votre compte est prêt ; il ne manque que le code du moment. |
| **« Mode développement local »** (bandeau en haut) | Vous êtes sur l'environnement de recette, où la seconde preuve est désactivée pour vous faciliter le travail. Ce bandeau n'apparaît jamais en production. |

## Si ça ne marche pas

| Ce que vous lisez | Ce que ça veut dire | Ce que vous faites |
|-------------------|---------------------|-------------------|
| **« Connexion impossible : … »** en rouge | L'e-mail ou le mot de passe ne correspond à aucun compte. | Vérifiez la casse de l'e-mail et le clavier (verrouillage des majuscules). Après plusieurs essais, attendez : la console bloque les tentatives répétées. |
| **« Vérification impossible : … »** | Le code est faux ou périmé. | Ouvrez votre application d'authentification, attendez le prochain code, saisissez-le. |
| Vous êtes renvoyé à la page de connexion alors que vous veniez de saisir le code | Votre session a expiré entre-temps. | Reprenez depuis l'étape 1. |
| **« Session expirée »** après une coupure | Votre session était fermée. | Reconnectez-vous : rien n'a été perdu, les actes déjà faits restent faits. |

## Et ensuite

- Vous voulez **ajouter un collègue** à la console : parcourez [F03 — Inviter un
  collègue et lui donner ses droits](F03-inviter-un-collegue-et-lui-donner-ses-droits.md).
- Vous voulez **retrouver ce qu'un collègue a fait** : parcourez [F04 — Retrouver qui a
  fait quoi](F04-retrouver-qui-a-fait-quoi.md).
