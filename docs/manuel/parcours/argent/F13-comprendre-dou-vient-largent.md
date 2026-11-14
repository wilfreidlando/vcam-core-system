---
numero: F13
titre: Comprendre d'où vient l'argent
situation: Lire le grand livre, les portefeuilles
famille: argent
famille_titre: Suivre et reprendre l'argent
profil: Opérateur
temps: 15 minutes
---

## Ce qu'il faut savoir avant

- Trois mots suffisent pour lire la comptabilité du projet. Une **écriture** est une
  ligne datée qui dit d’où vient l’argent et où il va. Un **compte** est une catégorie
  de sommes — une créance client, un revenu, une sortie d'argent. Un **solde** est ce
  qui reste sur un compte.
- La comptabilité est en **partie double** : toute somme passe **deux fois** — une fois
  en débit, une fois en crédit — et la somme de tous les soldes d'une devise vaut
  **zéro**. C'est la garantie que rien ne disparaît : ce qui est entré quelque part est
  forcément sorti d'ailleurs.
- Un **portefeuille**, ce n'est pas l'argent de la plateforme : c'est de l'argent
  **appartenant à quelqu'un** — un prestataire, un partenaire. Sa devise est la sienne,
  et ses sommes ne se mélangent pas à celles du projet.
- Un portefeuille est alimenté par un **paiement confirmé**, et par rien d'autre. La
  console ne crédite jamais un portefeuille à la main : tout ce que vous y voyez est
  le reflet d'un mouvement déjà décidé ailleurs.
- Tout ce que vous lisez ici est en **lecture seule**. Rien ne se modifie depuis ces
  écrans : une correction se propose ailleurs, et elle se voit ici une fois validée.

## Les étapes

### 1. Ouvrez la comptabilité du projet

Sur la fiche du projet, cliquez sur **« Comptabilité et balance »**. La page s'appelle
**« Comptabilité »**. Elle commence par un rappel : chaque ligne est une écriture
immuable, et une correction est toujours une nouvelle transaction, jamais la
modification d'une écriture existante.

### 2. Lisez la balance des comptes

Le tableau **« Balance des comptes »** est groupé par devise. Chaque ligne est un
compte : la colonne **« Compte »** dit de quoi il s'agit, la colonne **« Référence »**
précise à qui il appartient, les colonnes **« Débit »** et **« Crédit »** disent ce
qui est entré et ce qui est sorti, et la colonne **« Solde net (débit) »** donne ce qui
reste.

→ **Ce qui se passe** : le solde net est la différence entre ce qui est entré sur le
compte et ce qui en est sorti. S'il est positif, l'argent y est entré plus qu'il n'en
est sorti : c'est un compte qui retient de l'argent — une créance client, un compte
d'attente. S'il est négatif, c'est l'inverse — le compte a servi plus qu'il n'a reçu.

### 2 bis. Voyez tous les comptes du projet

La balance ne liste que les comptes **qui ont bougé**. Sous la balance, la section
**« Les comptes du projet »** montre **tous** les comptes du projet — y compris
ceux qui n'ont encore rien reçu et rien dû.

Le tableau **« Comptes du projet »** a une ligne par compte, avec les colonnes
**« Compte »**, **« Référence expliquée »**, **« Devise »**, **« Débit »**,
**« Crédit »** et **« Solde »**.

Chaque ligne dit **de quel argent** il s'agit, sous le nom du compte :

- **« l'argent du projet »** — les comptes du projet : **« Recettes »**,
  **« Encaissements »**, **« Encaissements — prestataire »** (l'argent retenu chez
  le service d'encaissement), **« Sorties de trésorerie »**, **« Exceptions de
  paiement »**, **« Reçus hors ligne »**, **« Créances irrécouvrables »** ;
- **« l'argent du client »** — **« Portefeuille client »** : c'est l'argent d'un
  client, celui d'un compte qui a été crédité parce qu'un paiement l'a demandé ;
- **« l'argent dû par le client »** — **« Créances clients »**.

La colonne **« Référence expliquée »** dit **de qui** c'est : le client, pour un
portefeuille ou une créance ; le nom que vous avez donné au service d'encaissement,
pour un compte de prestataire.

→ **Ce qui se passe** : un compte dont la référence ne se résout plus est dit tel
quel — **« Portefeuille inconnu »**, **« Client inconnu »**. Vous ne voyez jamais un
identifiant brut.

**Vous ne créez jamais un compte.** Les comptes sont créés **par le Core**, au moment
où de l'argent bouge ; il n'y a donc pas d'écran pour en créer un. C'est pour cela
que cette page est en lecture seule.

### 2 ter. Ouvrez les mouvements d'un compte

Sur la page de comptabilité, le lien **« Ouvrir les comptes et leurs mouvements »**
mène à la page **« Comptes du projet »**. Là, cliquez sur le nom d'un compte.

→ **Ce qui se passe** : la page s'ouvre sur les mouvements de ce compte — d'où vient
son solde, ligne par ligne.

### 3. Vérifiez que la devise s'équilibre

En tête de chaque devise, une pastille annonce **« Équilibrée · somme nulle »**, ou
**« Déséquilibre de … »**.

→ **Ce qui se passe** : cette pastille est le contrôle le plus important de la
comptabilité. Si elle dit **« Équilibrée »**, la somme de tous les soldes de la devise vaut
zéro : rien n'est entré sans être sorti d'ailleurs. Si elle annonce un déséquilibre,
quelque chose a été écrit à moitié : c'est le moment d'ouvrir **« Exceptions d'argent »**
dans la barre latérale.

### 4. Descendez dans les transactions

La rubrique **« Transactions (les plus récentes d'abord) »** liste les opérations, avec
leur date, leur type et leurs lignes d'écriture. Chaque ligne d'écriture montre le sens
(**débit** ou **crédit**), le compte touché et le montant : c'est là que vous lisez
l'écriture en clair. Le champ **« Type de transaction »** permet de ne garder qu'un
type d'opération ; le tag **« contre-passation »** signale les écritures qui annulent
une écriture précédente.

### 5. Ouvrez les portefeuilles du projet

Dans la barre latérale, **« Portefeuilles par projet »** mène à la liste des projets ;
sur la fiche du projet, le lien **« Portefeuilles de ce projet »** ouvre la page
**« Portefeuilles »**. Elle affiche le nombre de portefeuilles du projet et, par
devise, le total disponible et le total bloqué.

### 6. Suivez l'histoire d'un portefeuille

Cliquez sur **« Écritures → »** en bout de ligne. La page **« Écritures du
portefeuille »** donne le solde du portefeuille — **« Disponible »** et **« Bloqué »** —
puis l'historique : chaque ligne est datée, dit le sens (**crédit** ou **débit**), le
montant, la référence de l'opération et son identifiant.

→ **Ce qui se passe** : un portefeuille est **alimenté par un paiement confirmé**, et
par rien d'autre. Une somme peut ensuite être transférée ou retirée, mais ces gestes ne
créent rien : ils déplacent un argent déjà là. La première ligne de crédit de
l'historique est donc toujours un encaissement.

## Ce que vous voyez

| Sur l'écran | Ce que ça veut dire |
|-------------|--------------------|
| **« Débit »** / **« Crédit »** | Ce qui est entré sur le compte, ce qui en est sorti. Les deux colonnes ne se lisent pas l’une contre l’autre : elles se cumulent. |
| **« Solde net (débit) »** | Ce qui reste sur le compte. Positif, le compte doit de l'argent ; négatif, il en a avancé. |
| **« Équilibrée · somme nulle »** | La garantie que la devise s'équilibre : la somme de tous les soldes vaut zéro. |
| **« Déséquilibre de … »** | La somme des soldes de la devise n'est pas nulle. Quelque chose a été écrit à moitié. |
| **« contre-passation »** | Cette écriture en reprend une autre à l'envers : c'est une correction, pas une nouvelle opération. |
| **« Tiers »** (dans la liste des portefeuilles) | À qui appartient le portefeuille. |
| **« Disponible »** / **« Bloqué »** | La somme que le porteur peut utiliser, et celle qui est retenue pour l'instant. |
| **« Sens »** (**crédit** / **débit**) | L'argent entre sur le portefeuille, ou il en sort. |
| **« Comptes du projet »** | Tous les comptes du projet, y compris ceux qui n'ont pas encore bougé. |
| **« Ouvrir les comptes et leurs mouvements »** | Le lien qui mène à la page dédiée, où l'on ouvre les mouvements d'un compte. |
| **« Référence expliquée »** | De qui est l'argent : le client, pour un portefeuille ou une créance ; le service d'encaissement, pour un compte de prestataire. |
| **« l'argent du projet »** | Ce compte porte l'argent du projet. |
| **« l'argent du client »** | Ce compte porte l'argent d'un client — un portefeuille crédité sur demande d'un paiement. |
| **« l'argent dû par le client »** | Ce compte porte ce qu'un client doit au projet. |
| **« Portefeuille inconnu »** · **« Client inconnu »** | La référence ne se résout plus : le portefeuille ou le client a disparu. |
| **« Compte technique »** | Un compte d'une nature que la console ne connaît pas encore : il n'est pas caché, pour qu'on le voie. |
| **« Les mouvements du compte »** | Les écritures qui ont fait le solde du compte, de la plus ancienne à la plus récente. |
| **« Solde du compte : »** | Le solde tel qu'il est après les mouvements affichés. |
| **« Ce compte n'a encore jamais bougé. »** | Le compte existe, mais rien n'y est jamais entré ni sorti : il ouvre et ferme sur zéro. |
| **« Ce compte existe en plusieurs devises »** | Le même compte existe dans plusieurs devises : chacune a son propre solde. |
| **« Sens »** (**débit** / **crédit**) | Le sens de l'écriture sur ce compte. |
| **« Solde après l'écriture »** | Le solde du compte une fois cette écriture passée. |
| **« Mouvements plus récents »** · **« Mouvements plus anciens »** | La pagination du relevé. |
| **« Retour à la comptabilité »** | Le lien qui ramène à la page de comptabilité du projet. |

## Si ça ne marche pas

| Ce que vous lisez | Ce que ça veut dire | Ce que vous faites |
|-------------------|---------------------|-------------------|
| **« Aucune écriture pour ce projet. »** | Le projet n’a encore rien dans sa comptabilité. | Vérifiez que vous êtes sur le bon projet : son nom est écrit en haut de la page. |
| **« Aucune transaction. »** | Le projet a des comptes, mais aucune opération à afficher — ou le filtre **« Type de transaction »** ne correspond à rien. | Videz le champ **« Type de transaction »** et cliquez sur **« Filtrer »**. |
| **« Déséquilibre de … »** | La somme des soldes de la devise n’est pas nulle : une écriture a été commencée sans être terminée. | Ouvrez **« Exceptions d'argent »** dans la barre latérale et traitez l’exception ouverte. |
| **« Aucun portefeuille pour ce projet pour le moment. »** | Aucun paiement n’a encore versé sur un portefeuille de ce projet. | C’est normal tant que les paiements ne demandent pas de verse sur un portefeuille. |
| **« Aucun compte pour ce projet. »** | Le projet n’a encore aucun compte : rien n’a encore bougé dans sa comptabilité. | C’est normal au démarrage. Dès qu’un paiement ou une facture bouge de l’argent, les comptes apparaissent. |
| **« Aucune écriture pour ce portefeuille pour le moment. »** | Le portefeuille est vide : rien n’y est encore entré. | Vérifiez la colonne **« Portefeuille à créditer »** de la fiche du paiement concerné. |
| Vous ne voyez pas un portefeuille que vous cherchez | Les portefeuilles sont toujours rattachés à un projet : ils ne se voient que depuis la fiche d’un projet. | Revenez à **« Projets »**, ouvrez le bon projet, puis **« Portefeuilles de ce projet »**. |

## Et ensuite

- Ce qui vous est dû, et sur quelle facture : parcourez [F09 — Voir ce qui m’est dû et
  relancer](F09-voir-ce-qui-mest-du-et-relancer.md).
- Une exception d’argent, pour remettre les comptes d’aplomb : parcourez [F11 — Traiter
  une exception d’argent](F11-traiter-une-exception-dargent.md).
- Une écriture fausse : parcourez [F12 — Corriger une erreur
  d’écriture](F12-corriger-une-erreur-decriture.md).
