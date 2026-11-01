---
numero: F04
titre: Retrouver qui a fait quoi
situation: Chercher l'origine d'un acte
famille: acces
famille_titre: Se connecter et gérer les accès
profil: Support
temps: 3 minutes
---

## Ce qu’il faut savoir avant

- **Le journal d’audit est la trace de tous les gestes de la console.** Dès qu’un
  opérateur fait quelque chose — émettre une facture, inviter un collègue, changer un
  rôle, se connecter, se déconnecter — une ligne est écrite.
- **Il ne se réécrit jamais.** Les lignes sont figées : personne ne peut les modifier ni
  les effacer, pas même un administrateur. C’est ce qui en fait une preuve.
- **Vous ne voyez que votre périmètre.** Un opérateur d’organisation ne voit que les
  lignes de son organisation ; les gestes de la plateforme entière ne lui sont pas
  montrés.
- **Le journal ne contient aucun secret.** Ni mot de passe, ni clé d’API, ni donnée
  personnelle en clair.
- **Les filtres ne sont ni souples ni arrondis.** Ils comparent le texte exact : un mot
  comme `invoice`, ou sa traduction française, ne donne rien. Et les deux champs de
  date coupent la recherche à l’heure qu’il est au moment où vous filtrez, pas à minuit.

## Les étapes

### 1. Ouvrez le journal

Dans la barre latérale, à la section **« Plateforme »**, cliquez **« Journal d’audit »**. La
table des gestes s’affiche, de la plus récente à la plus ancienne.

### 2. Renseignez les filtres

Cinq filtres sont proposés : **« Ressource »**, **« Acteur »**, **« Action »**, **« Depuis le »** et
**« Jusqu’au »**. Remplissez ceux qui vous servent, puis cliquez sur **« Filtrer »**.

→ **Ce qui se passe** : la console ne garde que les lignes qui correspondent à tous les
filtres remplis. Un filtre laissé vide ne filtre rien.

### 3. Lisez la ligne

Chaque ligne est une date, un auteur, un acte, l’objet sur lequel le geste a porté, un
identifiant de liaison et un bouton qui déplie le détail.

### 4. Ouvrez le détail

Cliquez sur **« Voir ▸ »** à droite de la ligne. Trois blocs apparaissent, selon ce que le
geste a laissé : **« Avant »**, **« Après »** et **« Contexte »**.

→ **Ce qui se passe** : le détail montre l’état de l’objet avant le geste, après le
geste, et les informations qui entourent le geste — le projet concerné, par exemple. Le
bouton disparaît quand il n’y a rien à montrer.

### 5. Recommencez

Pour repartir de zéro, cliquez **« Réinitialiser »** : les cinq champs se vident et le
journal affiche de nouveau toutes les lignes que vous pouvez voir. **« Précédent »** et
**« Suivant »** font défiler les pages quand il y en a plus d’une.

## Ce que vous voyez

| Sur l’écran | Ce que ça veut dire |
|-------------|--------------------|
| **« Quand »** | La date et l’heure du geste, à la minute près. |
| **« Acteur »** | Qui a agé. Pour une personne, le libellé est suivi de son adresse e-mail. Pour un acteur automatique, la console écrit le genre à la place du nom : une clé d’API, un service, le système lui-même, ou le portail client. |
| **« Action »** | Ce que le geste a fait, en français : une facture émise, un reçu enregistré, un opérateur invité, un membre invité, un client créé, une connexion réussie, une déconnexion. |
| **« Ressource »** | Sur quoi le geste a porté : une facture, un paiement, un portefeuille, un client, un abonnement, une offre, une organisation, un projet, une notification. |
| **« Corrélation »** | Un identifiant technique, commun à tous les gestes d’une même requête. Il permet de retrouver la ligne dans les traces du serveur. |
| **« Détails »** | Le bouton **« Voir ▸ »** et les blocs **« Avant »**, **« Après »**, **« Contexte »**. |
| **« Identifiant brut, par ex. operator:… »** | Ce que le filtre **« Acteur »** attend : l’identifiant brut de l’auteur, pas le libellé affiché. Le filtre **« Ressource »** attend de même l’identifiant brut de l’objet. Survolez une cellule des colonnes **« Acteur »** et **« Ressource »** : l’identifiant complet y est écrit. |
| **« Code brut, par ex. api_key.issued »** | Ce que le filtre **« Action »** attend : le code de l’action, pas sa traduction française. |
| **« Réinitialiser »** | Le lien qui vide les cinq filtres. |
| **« Filtrer »** | Le bouton qui applique les filtres. |

## Si ça ne marche pas

| Ce que vous lisez | Ce que ça veut dire | Ce que vous faites |
|-------------------|---------------------|-------------------|
| Le tableau est vide, alors que le geste a bien eu lieu | Les filtres comparent le texte exact : un mot comme `invoice`, ou sa traduction française, ne donnent rien. | Videz les filtres avec **« Réinitialiser »** et repartez des dates seules, puis ajoutez un filtre à la fois. |
| Le filtre **« Acteur »** ne renvoie rien | Vous avez saisi le libellé ou une adresse e-mail ; le champ attend l’identifiant brut. | Survolez la cellule **« Acteur »** de la ligne que vous cherchez : l’identifiant complet y est écrit, copiez-le dans le filtre. |
| Le filtre **« Action »** ne renvoie rien | Vous avez saisi la traduction française ; le champ attend le code brut. | Videz le champ et laissez-le vide, ou saisissez le code que vous avez lu dans l’infobulle de la colonne **« Action »**. |
| Un geste de la journée même n’apparaît pas | Les deux champs de date coupent à l’heure qu’il est au moment où vous filtrez, pas à minuit. | Mettez la veille dans **« Depuis le »** et le lendemain dans **« Jusqu’au »**, pour englober la journée entière. |
| Vous voyez un libellé technique dans une colonne, au lieu d’un mot français | Le geste n’a pas encore de traduction dans la console. | Utilisez l’infobulle de la cellule : elle contient l’identifiant exact. Signalez la ligne à l’équipe qui exploite la console. |
| Le tableau ne contient aucune ligne | Aucun geste ne correspond aux filtres, ou les filtres portent sur une autre organisation. | Cliquez **« Réinitialiser »** pour tout voir, puis resserrez. |

## Et ensuite

- Vous voulez **savoir si un geste a bien eu lieu** : le journal vous le dira, ligne par
  ligne, avec son auteur et sa date.
- Vous voulez **confirmer un acte qui déplace de l’argent** : parcourez [F02 — Confirmer
  une action qui déplace de l’argent](F02-confirmer-une-action-qui-deplace-de-largent.md).
- Vous voulez **donner l’accès à un collègue** : parcourez [F03 — Inviter un collègue et
  lui donner ses droits](F03-inviter-un-collegue-et-lui-donner-ses-droits.md).
