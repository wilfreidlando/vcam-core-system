---
numero: F12
titre: Corriger une erreur d'écriture
situation: Une écriture est fausse
famille: argent
famille_titre: Suivre et reprendre l'argent
profil: Opérateur
temps: 10 minutes
---

## Ce qu’il faut savoir avant

- Une correction **ne modifie jamais** une écriture existante. Elle produit une **nouvelle
  écriture compensatoire**, qui reprend l’ancienne à l’envers et écrit le montant
  correct à côté. L’écriture d’origine reste visible et intacte dans la comptabilité :
  c’est ce qui permet de comprendre, six mois plus tard, ce qui s’est passé.
- Une correction **nomme toujours** l’opération qu’elle corrige. Sans elle, la
  correction est refusée : une correction qui ne dit pas ce qu’elle corrige n’est pas
  une correction.
- Elle porte toujours **deux comptes différents** : celui qui est débité (la source) et
  celui qui est crédité (la destination). Le même compte des deux côtés ne déplacerait
  aucune somme.
- Elle ne peut pas porter sur le **compte d’une partie** — un portefeuille : le solde
  d’un portefeuille se met à jour quand un paiement est confirmé, jamais par une
  correction comptable.
- Selon le montant, la correction est **comptabilisée tout de suite** par la personne
  qui la propose, ou elle **attend une seconde personne** avant d’être écrite dans la
  comptabilité.

## Les étapes

### 1. Ouvrez les corrections

Dans la barre latérale, **« Corrections d'exploitation »**. La liste donne, pour
chaque correction, son **« Statut »** — **« À valider »**, **« Validée »** ou
**« Refusée »** —, le **« Projet »**, la **« Source »**, la **« Destination »**, le
**« Montant »** et le **« Motif »**.

### 2. Proposez la correction

Cliquez sur **« Proposer une correction »**. La page commence par choisir le projet :
une correction ne porte que sur les écritures de son propre projet. Cliquez sur le
nom du projet dans la liste **« Sur quel projet ? »**.

### 3. Nommez l’écriture à contrer-passer

Le menu **« Transaction à contrer-passer »** propose les écritures les plus récentes du
projet. Pour une écriture plus ancienne, saisissez son identifiant dans
**« …ou une écriture plus ancienne (identifiant) »** : le champ libre l’emporte sur la
liste.

### 4. Choisissez les deux comptes et le montant

**« Type de compte débité »** (la source) et **« Type de compte crédité »** (la
destination). La console propose les comptes du projet : **« Créance client »**,
**« Revenu du projet »**, **« Encaissements en attente d'imputation »**,
**« Encaissements hors ligne »**, **« Encaissements non imputables »**,
**« Créances abandonnées »**, **« Sorties enregistrées »**, **« Versements en cours »**,
**« Frais du prestataire »**, **« Compte de bascule du prestataire »**,
**« Compte de contrepartie des corrections »**. Les champs **« Référence du compte
débité »** et **« Référence du compte crédité »** ne se remplissent que pour les
comptes qui appartiennent à quelqu’un — un prestataire, par exemple — et restent vides
pour un compte du projet.

Saisissez ensuite le **« Montant (unités mineures) »** et la **« Devise »**. Le montant
est toujours écrit dans la plus petite unité de la devise : 100 XAF valent 100 F CFA,
mais 100 USD valent 1,00 dollar.

### 5. Écrivez le motif et proposez

Le **« Motif »** est obligatoire : c’est ce qui reste de la décision quand on la relit
dans six mois. Cliquez sur **« Proposer la correction »**.

→ **Ce qui se passe** : la correction est enregistrée et attend d’être validée. Si le
montant est sous le seuil de double validation, elle est **comptabilisée sur-le-champ**
et vous lisez **« Correction proposée et écrite au grand livre »** ; au-dessus, elle
attend une seconde personne.

### 6. Faites valider par un collègue

Sur la correction proposée, la rubrique **« Validation par une seconde personne »**
propose **« Valider et comptabiliser »** et **« Refuser »**. Si vous êtes la personne
qui a proposé, la page vous dit qu’une autre personne doit valider.

→ **Ce qui se passe** : **« Valider et comptabiliser »** écrit la correction dans la
comptabilité du projet. **« Refuser »** écarte la proposition : rien n’est écrit, et la
correction reste **« Refusée »**.

## Ce que vous voyez

| Sur l’écran | Ce que ça veut dire |
|-------------|--------------------|
| **« Source »** / **« Destination »** | Les deux comptes que la correction touche : celui qui est débité, celui qui est crédité. |
| **« Transaction à contrer-passer »** | L’écriture que la correction annule. |
| **« Transaction dans les comptes »** | L’écriture que la correction a produite, une fois validée. |
| **« Transaction contrer-passée »** | L’écriture que la correction reprend à l’envers. |
| **« Motif »** | La raison de la correction. Elle est obligatoire : c’est ce qui reste de la décision quand on la relit dans six mois. |
| **« Proposée par »** / **« Validée par »** | Qui a proposé, qui a validé. |
| **« À valider »**, **« Validée »**, **« Refusée »** | Les trois états d’une correction. |

## Si ça ne marche pas

| Ce que vous lisez | Ce que ça veut dire | Ce que vous faites |
|-------------------|---------------------|-------------------|
| **« La correction doit nommer la transaction qu'elle contre-passe : choisissez-la dans la liste, ou saisissez son identifiant. »** | Vous n’avez pas dit quelle écriture est corrigée. | Choisissez-la dans **« Transaction à contrer-passer »**, ou saisissez son identifiant dans le champ libre. |
| **« La correction doit porter sur deux comptes différents. »** | La source et la destination sont le même compte : rien n’aurait bougé. | Choisissez un autre compte de destination. |
| **« Une correction d'exploitation ne peut pas porter sur le compte d'une partie (wallet) : … »** | Vous avez choisi un compte de portefeuille. | Corrigez par un encaissement ou un paiement, pas par une correction comptable. |
| Le message se termine par **« n'existe pas : il n'y a rien à contrer-passer. »** | L’identifiant saisi ne correspond à aucune écriture. | Reprenez la liste **« Transaction à contrer-passer »**, et vérifiez que vous êtes sur le bon projet. |
| Le message dit **« appartient à un autre projet »** | L’écriture que vous visez est celle d’un autre projet. | Une correction ne contre-passe que les écritures de son propre projet : choisissez une écriture de ce projet. |
| **« Correction proposée : elle attend la validation d'une seconde personne. »** | La correction dépasse le seuil : elle n’est pas encore dans les comptes. | Faites-la valider par un collègue. |
| **« Correction refusée : rien n'a été écrit au grand livre. »** | Un collègue a refusé la correction. | Relisez le motif, et reproposez si nécessaire. |

## Et ensuite

- L’argent ne s’équilibre pas et vous ne savez pas où : parcourez [F11 — Traiter une
  exception d’argent](F11-traiter-une-exception-dargent.md).
- Vous voulez comprendre d’où vient l’argent : parcourez [F13 — Comprendre d’où vient
  l’argent](F13-comprendre-dou-vient-largent.md).
- Un paiement est bloqué : parcourez [F10 — Retrouver ou relancer un
  paiement](F10-retrouver-ou-relancer-un-paiement.md).
