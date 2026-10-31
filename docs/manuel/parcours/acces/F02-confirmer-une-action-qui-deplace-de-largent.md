---
numero: F02
titre: Confirmer une action qui déplace de l'argent
situation: Valider une facture, un reçu, une correction
famille: acces
famille_titre: Se connecter et gérer les accès
profil: Opérateur
temps: 1 minute
---

## Ce qu’il faut savoir avant

- **Une seule famille de gestes demande une confirmation : ceux qui déplacent de
  l’argent.** Émettre ou annuler une facture, valider un encaissement reçu par ailleurs,
  trancher une exception d’argent, approuver une correction d’écriture : voilà la liste.
  Inviter un collègue, émettre une clé d’API, changer un rôle d’organisation ou effacer
  la fiche d’un client ne demandent **rien** : votre connexion a déjà prouvé qui vous
  êtes.
- **La confirmation vaut cinq minutes.** Une fois le code saisi, vous pouvez enchaîner
  plusieurs gestes d’argent sans ressaisir de code. Passé cinq minutes, il faut
  recommencer.
- **Le code ne sert qu’une fois.** Un code déjà utilisé est refusé. Votre application
  d’authentification en affiche un nouveau toutes les trente secondes.
- **La confirmation est liée à votre session.** L’ouvrir dans un autre navigateur, ou
  depuis un autre poste, ne vous donne rien.
- **Lire ne demande jamais de code.** Vous pouvez parcourir tous les écrans, autant de
  fois que vous voulez : seule l’écriture qui déplace de l’argent est interrompue.

## Les étapes

### 1. Faites le geste qui déplace de l’argent

Sur la fiche d’une facture, cliquez sur **« Émettre »**, **« Annuler la facture »** ou
**« Passer en irrécouvrable »**. Sur la liste des encaissements, cliquez sur **« Valider »**. Sur
une exception d’argent ou une correction d’écriture, cliquez sur **« Enregistrer la décision »** ou **« Valider et comptabiliser »**.

→ **Ce qui se passe** : la console n’exécute pas le geste. Elle ouvre un autre écran, qui
vous demande un code.

### 2. Saisissez le code à 6 chiffres

L’écran affiche le titre **« Confirmez votre identité »**, un champ **« Code de l’application d’authentification »** et le bouton **« Confirmer »**. Recopiez le code affiché par votre application d’authentification, puis cliquez sur **« Confirmer »**.

→ **Ce qui se passe** : la console vérifie le code, vous ramène sur l’écran du geste et
vous annonce que l’identité est confirmée pendant cinq minutes. **Le geste n’a pas encore
été fait** : c’est vous, pas la console, qui allez le relancer.

### 3. Relancez le geste

Vous êtes de retour sur la fiche de la facture, du reçu, de l’exception ou de la
correction. Cliquez une seconde fois sur le même bouton : cette fois, l’action est
exécutée.

→ **Ce qui se passe** : la facture est émise, l’encaissement est validé, la correction est
écrite dans les comptes. Le geste est inscrit dans le journal d’audit, avec votre nom.

## Ce que vous voyez

| Sur l’écran | Ce que ça veut dire |
|-------------|--------------------|
| **« Confirmez votre identité »** | La console va exécuter un geste qui déplace de l’argent ; elle veut une preuve fraîche. |
| Le texte sous le titre, qui explique que le code est celui de votre application d’authentification et qu’il reste valable 5 minutes pour les actions suivantes | Une seule saisie vous ouvre la porte des gestes d’argent pendant cinq minutes. |
| **« Code de l’application d’authentification »** | Le champ où vous recopiez le code à 6 chiffres de votre application. |
| **« Confirmer »** | Le bouton qui envoie le code. |
| **« Confirmation impossible : »** (encadré rouge) | Le code n’a pas été accepté ; rien n’a été exécuté. |
| **« Identité confirmée pendant 5 minutes. »** (bandeau vert) | Le code est bon : relancez votre geste. |

## Si ça ne marche pas

| Ce que vous lisez | Ce que ça veut dire | Ce que vous faites |
|-------------------|---------------------|-------------------|
| **« Code invalide, expiré ou déjà utilisé. Attendez le prochain code de votre application. »** | Le code est faux, périmé, ou déjà consommé. | Attendez le code suivant dans votre application, saisissez-le, renvoyez. |
| **« Confirmation impossible : »** et rien d’autre | Le champ est resté vide. | Recopiez le code à 6 chiffres et cliquez sur **« Confirmer »**. |
| Votre code est bon, mais le bouton du geste est toujours là, inchangé | C’est normal : le geste n’avait pas été exécuté. | Cliquez une seconde fois sur le même bouton. |
| Vous êtes renvoyé vers la page de connexion | Votre compte a été révoqué entre-temps. | Prévenez l’administrateur de la plateforme : il doit vous inviter de nouveau. |
| Trop de codes refusés d’affilée | La console limite le nombre d’essais. | Attendez une minute avant de réessayer. |

## Et ensuite

- Vous n’êtes pas encore connecté : parcourez [F01 — Se connecter et confirmer mon
  identité](F01-se-connecter-et-confirmer-mon-identite.md).
- Vous voulez **ajouter un collègue** à la console : parcourez [F03 — Inviter un
  collègue et lui donner ses droits](F03-inviter-un-collegue-et-lui-donner-ses-droits.md).
- Vous voulez **retrouver ce qu’un collègue a fait** : parcourez [F04 — Retrouver qui a
  fait quoi](F04-retrouver-qui-a-fait-quoi.md).
