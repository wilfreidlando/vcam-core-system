---
numero: F19
titre: Piloter par les indicateurs
situation: Savoir comment va le projet
famille: piloter
famille_titre: Extraire et piloter
profil: Commerce
temps: 5 minutes
---

## Ce qu’il faut savoir avant

- **Les courbes ne bougent pas dans la journée.** Elles sont dessinées à partir
  d’un relevé pris chaque nuit : ce que vous voyez ce matin est l’état d’hier
  soir. Ce n’est pas une panne, c’est le rythme.
- **Les quatre cartes, elles, sont à jour.** Le revenu récurrent, l’encaissé du
  mois, les créances et l’attrition se lisent au jour le jour.
- **Chaque devise a ses propres chiffres.** Rien n’est converti : une courbe par
  devise, et une ligne par devise dans le tableau des créances.
- **La liste du bas ne se coche pas à la main.** Elle dit ce qui manque au
  projet pour encaisser, et les points passent au vert tout seuls, au fur et à
  mesure que vous branchez les choses.
- **Les chiffres d’argent ne sont pas réservés à tout le monde.** Un compte qui
  ne lit pas la comptabilité du projet voit la liste de contrôle, mais ni les
  cartes ni les courbes.

## Les étapes

### 1. Ouvrir la page des indicateurs

La page d’accueil de la console — le tableau de bord — parle de la plateforme
entière : les organisations et les projets qu’elle héberge, les paiements des
dernières vingt-quatre heures, ceux qui sont bloqués, les notifications
automatiques en échec. Pour juger **un** projet, il faut sa page d’indicateurs.

Dans la barre latérale, **« Projets »** mène à la liste des projets. Cliquez sur
**« Voir → »** pour le projet qui vous intéresse, puis sur **« Indicateurs »**.

→ **Ce qui se passe** : la page s’ouvre sur le nom du projet, avec quatre cartes
de chiffres, des courbes, le tableau des créances et une liste de contrôle.

### 2. Choisir la période

En haut à droite, trois boutons de période : **30 jours**, **90 jours**,
**365 jours**. Le premier est sélectionné par défaut.

→ **Ce qui se passe** : les courbes se redessinent sur la durée choisie, et le
bouton sélectionné reste marqué.

### 3. Lire les quatre cartes

- **« Revenu récurrent mensuel »** — le revenu des contrats actifs, tant que
  leur période n’est pas payée, ramené au mois.
- **« Encaissé ce mois »** — ce qui est réellement rentré.
- **« Créances »** — ce que les clients du projet vous doivent.
- **« Attrition du mois »** — la part de clients perdue sur le mois. Sous le
  pourcentage, le nombre de clients perdus et le nombre de nouveaux clients.

### 4. Lire les courbes

Sous le titre **« Courbes »**, trois graphiques par devise : le revenu
récurrent, l’encaissé du mois et les créances. Chacun porte son titre :
**« Revenu récurrent mensuel »**, **« Encaissé du mois »**, **« Créances »**.

Chaque graphique montre la dernière valeur connue en haut, le premier et le
dernier jour en bas, et le plus haut atteint sur la période.

→ **Ce qui se passe** : chaque point de la courbe est un relevé fait dans la
nuit. Pendant la journée, la courbe reste la même : c’est le lendemain matin
qu’elle gagne un point, pour la journée qui vient de s’achever.

### 5. Lire la répartition des créances

Le tableau **« Créances par ancienneté »** range, pour chaque devise, ce que les
clients doivent selon leur retard : **« Non échues »**, **« 1 à 30 jours »**,
**« 31 à 60 jours »**, **« Plus de 60 jours »**. Une facture échue depuis plus de
deux mois s’affiche en rouge.

Sous le tableau, un lien mène à **« les factures du projet »** : c’est là que vous
voyez le client à relancer, facture par facture.

### 6. Descendre à la liste de contrôle

Le dernier bloc s’intitule **« Ce projet est-il prêt à encaisser ? »**. Sept
points, chacun avec une pastille : une coche verte quand c’est fait, un point
d’exclamation orange quand cela reste à faire, un tiret gris quand le point ne
concerne pas ce projet.

- **Une clé d’API a déjà servi** — le projet a déjà appelé la plateforme au
  moins une fois.
- **Un webhook actif et testé** — le projet est prévenu quand un abonnement
  change, et une livraison a déjà abouti.
- **Une application prestataire enregistrée** — le prestataire sait identifier
  le projet.
- **Une offre publiée** — sans elle, personne ne peut s’abonner.
- **Un portail personnalisé** — le logo, la couleur et la page de support du
  projet.
- **Une session de notification enregistrée** — le projet envoie des
  notifications : il lui faut une session pour le canal qu’il utilise.
- **Attribution des usages obligatoire** — une offre qui limite l’usage exige
  que chaque client soit identifié, sinon il peut contourner sa limite.

Si un point obligatoire manque alors que le projet est actif, un bandeau
d’avertissement s’affiche au-dessus de la liste.

→ **Ce qui se passe** : la liste est recalculée à chaque consultation, d’après
l’état réel du projet. Vous n’avez rien à cocher.

## Ce que vous voyez

| Sur l’écran | Ce que ça veut dire |
|-------------|--------------------|
| Les quatre cartes en haut | L’état du projet aujourd’hui : revenu récurrent, encaissé, créances, clients perdus. |
| **« Courbes »** | L’évolution sur la période choisie, une courbe par devise. |
| Un encadré gris à la place des courbes | Le projet n’a pas encore d’historique : les courbes se traceront à partir de la nuit. |
| Une phrase en ambre sous un graphique | **« Certains jours ont été reconstruits »** : ces jours-là ont été recalculés plus tard, leurs chiffres sont ceux du jour de la reprise, pas ceux du jour affiché. |
| **« Créances par ancienneté »** | Le retard de paiement, par tranche et par devise. |
| **« Ce projet est-il prêt à encaisser ? »** | La liste de contrôle : ce qui reste à brancher pour que le projet puisse encaisser. |
| Une coche verte | Le point est fait. |
| Un point d’exclamation orange | Le point concerne ce projet, et il n’est pas encore fait. |
| Un tiret gris | Le point ne concerne pas ce projet : il n’entre pas en ligne de compte. |
| Un bandeau orange au-dessus de la liste | Le projet est actif, mais il lui manque une pièce obligatoire pour encaisser. |
| **« les factures du projet »** | Le lien qui mène à la liste des factures, pour relancer un client. |

## Si ça ne marche pas

| Ce que vous lisez | Ce que ça veut dire | Ce que vous faites |
|-------------------|---------------------|-------------------|
| Un encadré gris à la place des courbes, qui annonce qu’il n’y a pas encore d’historique | Le projet n’a pas encore une nuit de chiffres derrière lui. | Revenez le lendemain : la courbe se trace à partir de la nuit. |
| La courbe n’a pas bougé depuis ce matin | C’est normal : elle se complète la nuit. | Pour l’état du jour, lisez les quatre cartes du haut. |
| Un bandeau orange au-dessus de la liste | Le projet est actif, mais il lui manque une pièce pour encaisser. | Corrigez les points marqués d’un point d’exclamation, en partant de la page du projet. |
| Les quatre cartes et les courbes ne sont pas là | Votre compte n’a pas le droit de lire la comptabilité de ce projet. | Demandez à un responsable de l’organisation de vous donner ce droit. |
| Un point de la liste est gris, avec un tiret | Ce point ne concerne pas ce projet. | Rien à faire : il ne compte pas. |
| Un montant en rouge dans la colonne **« Plus de 60 jours »** | Des factures sont en retard de plus de deux mois. | Ouvrez **« les factures du projet »** et relancez ces clients. |

## Et ensuite

- Vous voulez **sortir les données du projet** : parcourez [F18 — Extraire les
  données d’un projet](F18-extraire-les-donnees-dun-projet.md).
- Vous voulez **voir qui doit de l’argent** : sur la page du projet, le lien
  **« Créances »** mène à la liste des factures à relancer.
