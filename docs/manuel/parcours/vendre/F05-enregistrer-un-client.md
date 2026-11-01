---
numero: F05
titre: Enregistrer un client
situation: Créer une fiche client
famille: vendre
famille_titre: Vendre et encaisser
profil: Commerce
temps: 5 minutes
---

## Ce qu’il faut savoir avant

- **La référence du client est sa clé.** C’est l’identifiant que votre projet lui
  connaît déjà — son numéro de dossier, son code chez vous. Elle ne se change
  plus : une fois la fiche créée, le champ est verrouillé.
- **Si la référence existe déjà, vous ne créez pas une seconde fiche : vous
  modifiez la première.** Le bandeau vert vous annonce une création dans les
  deux cas : vérifiez donc la référence avant de valider.
- **Le contact n’est jamais affiché en clair.** Vous le saisissez une fois ; la
  console ne le montre plus que masqué, et le champ repart vide. Un champ laissé
  vide conserve ce qui était enregistré ; un champ rempli le remplace.
- **Un client suspendu perd ses droits, mais reste dû.** Ses factures et ses
  abonnements continuent d’exister : c’est l’accès au service qui est coupé.
- **Effacer un client est définitif.** L’effacement détruit son nom, son contact
  et son téléphone, et arrête ses abonnements en cours. Ses factures, elles,
  restent dues.

## Les étapes

### 1. Ouvrir la liste des clients

Depuis la fiche du projet, le lien **« Clients de ce projet »**. La page
**« Clients »** s’ouvre : une barre de recherche (**« Rechercher un client »** et
le bouton **« Rechercher »**), le bouton
**« Nouveau client »**, et un tableau aux colonnes **« Référence »**,
**« Nom »**, **« Contact »**, **« Statut »** et **« Version »**.

→ **Ce qui se passe** : la liste des clients du projet s’affiche. Une fiche déjà
déclarée porte un statut — **« Actif »**, **« Suspendu »** ou **« Effacé »** —
et le lien **« Fiche »** en face de la ligne ouvre son dossier complet.

### 2. Cliquer sur « Nouveau client »

Le formulaire **« Nouveau client »** s’ouvre.

### 3. Remplir la fiche

- **« Référence chez le projet »** — obligatoire. L’identifiant que votre projet
  connaît déjà : lettres, chiffres, et les caractères `.` `_` `:` `-`.
- **« Nom affiché »** — obligatoire. Le nom que l’équipe verra partout.
- **« Contact (courriel) »** — facultatif. L’adresse à qui écrire.
- **« Téléphone »** — facultatif.
- **« Langue »** — facultatif ; elle vaut `fr` si vous la laissez vide.

→ **Ce qui se passe** : rien n’est encore enregistré. Vous pouvez encore changer
d’idée, ou revenir à la liste par **« Annuler »**.

### 4. Déclarer le client

Cliquez sur **« Déclarer le client »**.

→ **Ce qui se passe** : la fiche est enregistrée. Vous revenez à la liste des
clients, et un bandeau vert vous confirme l’opération.

### 5. Ouvrir la fiche du client

Cliquez sur **« Fiche »** en face de la ligne du client. Sa page rassemble tout
ce qui le concerne : les droits et l’usage, les abonnements, les factures et les
règlements.

→ **Ce qui se passe** : la carte **« Droits et usage »** montre ce à quoi le
client a droit aujourd’hui ; les cartes **« Abonnements »**,
**« Factures récentes »** et **« Règlements »** montrent son historique. C’est
aussi là que vous souscrivez une offre, avec le bouton **« Créer l’abonnement »**.

## Ce que vous voyez

| Sur l’écran | Ce que ça veut dire |
|-------------|--------------------|
| **« Référence »** | L’identifiant du client chez le projet. Une facture, un abonnement ou un règlement la reprennent tous : c’est par elle qu’on retrouve le client. |
| **« Nom affiché »** | Le nom que l’équipe voit dans les listes, les abonnements et les factures. |
| **« Contact »** (colonne de la liste) | Le contact du client, masqué : il n’est jamais montré en clair. |
| **« Statut »** — **« Actif »** | Le client a accès à son offre. |
| **« Statut »** — **« Suspendu »** | Le client n’a plus accès à son offre, mais reste dû. Le bouton **« Réactiver »** lui rend ses droits. |
| **« Statut »** — **« Effacé »** | L’identité a été détruite : la ligne ne porte plus qu’une référence, ni nom ni contact. L’historique reste, et les factures restent dues. |
| **« Version »** | Le numéro de révision de la fiche. Il sert à savoir si quelqu’un d’autre a modifié le client entre-temps. |
| **« Droits et usage »** | Ce à quoi le client a droit aujourd’hui, et combien il a déjà consommé de chaque quota. |
| **« Règlements »** | Tout ce que le client a réglé, dans l’ordre : les paiements en ligne et les reçus hors ligne. |
| Un bandeau vert en haut de la page | L’acte a été fait. |
| Un bandeau rouge en haut de la page, avec **« Le formulaire n’a pas pu être enregistré »** et une liste de messages | L’acte a été refusé. Les messages sont aussi attachés aux champs, dans le formulaire. |

## Si ça ne marche pas

| Ce que vous lisez | Ce que ça veut dire | Ce que vous faites |
|-------------------|---------------------|-------------------|
| **« Le formulaire n’a pas pu être enregistré »** et une liste de messages | La fiche a été refusée : un champ obligatoire est vide, la référence contient un caractère interdit, ou une autre fiche du projet porte déjà cette adresse de contact. | Corrigez ce qui est signalé, champ par champ, et validez de nouveau. |
| La fiche que vous venez de créer ne porte pas le nom que vous avez saisi | La référence existait déjà : vous avez modifié l’ancienne fiche, pas créé une nouvelle. | Cliquez sur **« Fiche »**, vérifiez, puis remettez le bon nom avec **« Modifier »**. |
| Un bandeau rouge après **« Modifier »**, sans détail sur le champ | Quelqu’un d’autre a modifié la fiche entre-temps. | Rechargez la page, vérifiez la modification, puis enregistrez à nouveau. |
| Le client n’apparaît pas, même après une recherche | Il appartient à un autre projet. | Chaque projet a ses propres clients : ouvrez le bon projet. |
| **« Suspendre »** puis **« Réactiver »** ne changent rien sur la fiche du client | La suspension coupe l’accès au service, pas l’histoire : les factures et les abonnements restent. | Regardez les cartes **« Abonnements »** et **« Factures récentes »** pour vérifier que rien n’a disparu. |

## Et ensuite

- Vous voulez **abonner ce client à une offre** : parcourez [F06 — Vendre une offre et la faire vivre](F06-vendre-une-offre-et-la-faire-vivre.md).
- Vous voulez **lui facturer une période** : parcourez [F07 — Émettre une facture](F07-emettre-une-facture.md).
