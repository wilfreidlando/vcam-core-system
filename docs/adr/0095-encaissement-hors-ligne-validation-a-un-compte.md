# ADR-0095 — L'encaissement hors ligne se valide à un compte

- **Statut** : Accepté
- **Date** : 2026-10-28
- **Décideurs** : le responsable du produit
- **Amende** : ADR-0073 §4 (le reçu hors ligne), ADR-0094 §13 (l'approbation)
- **Dérives concernées** : D6 intégrité financière

## Contexte

ADR-0073 §4 prescrivait que l'encaissement hors ligne soit soumis à une
**double validation** : « l'auteur ne peut pas valider sa propre saisie
(contrainte en base) », et la base portait la contrainte
`manual_receipts_four_eyes`. ADR-0094 §13 précisait la suite : au-dessus
du seuil du projet, le reçu attendait une seconde validation avant de
devenir un fait comptable.

Le lot 11b livre ce mécanisme. Le responsable du produit a tranché, le
2026-10-28 : **un encaissement hors ligne peut être validé par un seul
compte** — l'auteur compris.

Un ADR accepté ne se réécrit pas : la décision est consignée ici, et les
deux ADR cités sont amendés, pas réécrits.

## Décision

### 1. La validation d'un encaissement hors ligne ne demande qu'un compte

`manual_receipts_four_eyes` **tombe** : la base n'interdit plus qu'un
compte valide sa propre saisie. Le domaine ne refuse plus
l'auto-validation.

Ce qui reste est l'acte lui-même : il est explicite, il est daté, il
porte le compte qui l'a fait, et il est **audité** comme tout autre geste
de l'exploitant (ADR-0083 §4). Le reçu conserve son auteur
(`recorded_by`) et son validateur (`approved_by`) — ils peuvent être le
même compte, et c'est visible.

### 2. Le seuil garde son rôle

Le seuil du projet
(`billing_settings.manual_receipt_approval_threshold_minor`) ne porte
plus sur le **nombre** de comptes, mais sur le **moment** :

- **sous** le seuil, le reçu est comptabilisé à la saisie ;
- **au-dessus**, il est enregistré « en attente » et attend un acte de
  validation explicite avant de compter.

C'est un contrôle de **délibération**, non plus de séparation des
tâches : l'argent ne compte pas par inadvertance, il compte parce que
quelqu'un l'a décidé.

### 3. Le refus reste possible

La validation peut être **refusée** : rien n'est comptabilisé, la trace
reste, et le reçu porte son état final « refusé ». Le refus est
audité comme l'acceptation.

## Conséquences

- Le contrôle à quatre mains de l'encaissement hors ligne est **retiré**.
  Les autres contrôles du Core ne bougent pas : la résolution des
  exceptions d'argent (ADR-0077) et les corrections d'exploitation
  (ADR-0076 §6) restent à quatre mains.
- Le seuil n'est plus une barrière de validation mais un seuil de
  comptabilisation différée.
- L'interface de console n'interdit plus à l'auteur de valider sa propre
  saisie : le bouton est là, et l'audit dit qui.

## Vérification

- Test : un reçu hors ligne au-dessus du seuil est enregistré « en
  attente » et ne bouge rien au grand livre.
- Test : la validation par **le compte qui l'a enregistré** est acceptée
  et comptabilise le reçu.
- Test : le refus ne comptabilise rien et laisse la trace.
