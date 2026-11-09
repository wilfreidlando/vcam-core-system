# ADR-0117 — Suppléments de quota : une quantité achetée s'ajoute à la limite existante, sur le même compteur

- **Statut** : Proposé, amendé par ADR-0121 (§2 et §3)
- **Date** : 2026-11-01
- **Décideurs** : comité d'architecture
- **Dérives concernées** : D5 contrat · D6 intégrité financière
- **Modifie le graphe** : non — `billing` garde ses dépendances (aucune arête nouvelle)

## Contexte

Une entreprise qui vend un service par abonnement mesure souvent ses usages :
des messages WhatsApp envoyés, des appels d'API, des documents produits.
Un plan porte alors une **limite** sur un compteur que le projet nomme
(ADR-0071 §3), et l'autorité de quota — celle du lot 12 — refuse la
consommation au-delà (ADR-0074 §2, §3).

Le client atteint cette limite. Il veut continuer à envoyer ses messages
du mois en cours. Il ne veut **pas** renouveler son abonnement : son
plan, sa période et son compteur sont bons, c'est la quantité qui manque.

Ce que le modèle actuel ne sait pas faire :

- la limite est **figée** pour la fenêtre (ADR-0074 §3) : `limit_value`
  est la valeur de la version de plan au moment où la fenêtre s'ouvre ;
- une seconde subscription ne s'ajoute pas au compteur : le compteur est
  indexé par `(project_id, customer_id, meter, window_start)` — un client,
  un compteur, une fenêtre (ADR-0074 §1) ;
- il n'existe **aucune** notion de pack, de supplément ou d'add-on dans le
  module `billing` : rien à acheter, rien à facturer, rien à ajouter.

Le besoin est donc un **supplément** : une quantité achetée qui
s'ajoute à la limite existante, sur le même compteur, dans la même
fenêtre, sans toucher au contrat.

## Décision

### 1. Le supplément est une entité, pas une subscription

Un supplément est **attaché à un abonnement** et porte :

- le **compteur** qu'il étend (le nom du compteur du projet,
  ADR-0071 §3) et la clé de la fonctionnalité du plan qu'il étend ;
- la **quantité** d'unités ajoutées à la limite ;
- le **prix unitaire** en unités mineures de la devise du contrat (G5) ;
- la **facture** qui le porte ;
- l'**état** de la purchase.

Ses termes sont **figés** à l'achat, comme ceux d'un contrat
(ADR-0072 §1) : le compteur, la quantité, le prix et la devise ne
bougent plus, même si le plan change ensuite.

Un supplément n'est **ni** une subscription, **ni** un second compteur,
**ni** un renouvellement. Le client qui achète un pack de cinquante
messages garde son plan, sa période, son compteur et ses cent messages
consommés.

La table est `subscription_supplements` ; elle ne porte **aucune**
contrainte SQL (le schéma ne porte pas de règle métier, ADR-0074 §1).

### 2. La vie d'un supplément : l'allocation n'est accordée qu'une fois payée

Trois états :

| État | Quand | Effet sur la limite |
| ---- | ----- | ------------------- |
| `pending` | la commande est passée, la facture est émise et ouverte | **aucun** |
| `active` | la facture est réglée | la quantité s'ajoute à la limite |
| `canceled` | la facture a été annulée avant tout encaissement | **aucun** |

L'activation se produit **dans la transaction qui règle la facture** —
en ligne (ADR-0073 §2) ou hors ligne (ADR-0073 §4) — et jamais avant :
une commande non payée n'est pas un droit. L'annulation de la facture
annule le supplément qu'elle portait ; une facture ne peut être annulée
qu'avant tout encaissement (ADR-0073 §3), donc un supplément `active`
ne revient jamais en arrière.

### 3. La limite effective : une seule source de vérité

> **limite effective d'un compteur = limite de la version de plan
> + Σ des quantités des suppléments `active` de l'abonnement**

Cette somme est calculée **en un seul endroit** —
`Application/Service/EffectiveLimitResolver` — et lue par les deux
consommateurs :

- la consommation mesurée (`ConsumeUsageUseCase`) : c'est la limite qui
  est dépensée et refusée ;
- les droits (`GetEntitlementsUseCase`, `GET
  /v1/customers/{ref}/entitlements`) : c'est la limite qui est annoncée.

Il n'y a donc **pas deux calculs** : le nombre qu'un client voit et le
nombre contre lequel le quota est appliqué ne peuvent pas diverger.

**Ce que cela change dans ADR-0074 §3.** La limite du plan reste figée
pour la fenêtre — un changement de plan ouvre une nouvelle période,
donc une nouvelle fenêtre — mais la **limite effective**, elle, bouge :
c'est le sens de l'amendement. Dans la base, `usage_counters.limit_value`
n'est plus l'image d'une valeur figée mais la **projection de la limite
effective**, rafraîchie à chaque admission ; la vérification elle-même
porte sur la valeur résolue par l'appelant, de sorte qu'une écriture
concurrente et périmée ne peut pas rendre une admission plus laxiste que
la règle.

Une limite de `null` reste **illimitée** (ADR-0071 §3) : un supplément
sur un compteur sans plafond est **refusé** — il n'y a rien à ajouter.

### 4. Le chemin d'achat

`POST /v1/subscriptions/{id}/supplements` (portée `subscriptions:write`,
comme un changement de plan — ADR-0072 §5), avec un **`Idempotency-Key`**
obligatoire (G7 : la route émet une facture et inscrit une créance au
grand livre).

Le corps déclare le compteur, la quantité et le prix unitaire. Le Core
**refuse** un compteur que le plan du contrat ne porte pas, un compteur
sans plafond, une quantité non positive et un prix négatif. Il ne
refuse pas un prix : **le projet declare ses conditions** — exactement
comme les lignes d'une facture manuelle (ADR-0073 §1). Le Core calcule
le total ; il n'invente jamais de prix.

La commande produit, dans **une seule transaction** :

1. le supplément, `pending`, aux termes figés ;
2. la **facture** qui le porte, créée puis **émise** par le module
   facture — le numéro est attribué sous le verrou de la séquence, la
   créance est inscrite au grand livre (ADR-0094 §6) ;
3. l'événement `subscription.supplement_purchased` (G8).

La facture **ne facture pas une période** : son `period_id` est vide,
pour que son règlement ne libère jamais une période du contrat
(ADR-0094 §8).

La ligne de facture est de nature `manual` : le registre des natures de
ligne (ADR-0073 §1) **n'est pas ouvert** pour un supplément — l'ouvrir
exigerait de modifier la contrainte `invoice_lines_kind_check`, et rien
dans la décision ne le justifie. Le lien entre la ligne et le
supplément est porté par `subscription_supplements.invoice_id`.

### 5. Les événements

| Événement | Quand |
| --------- | ----- |
| `subscription.supplement_purchased` | la commande est passée |
| `subscription.supplement_activated` | la facture est réglée |
| `subscription.supplement_canceled` | la facture est annulée |

Ils rejoignent le catalogue des événements souscrits (ADR-0107 §3) :
`WebhookEventTypes::ALL`. Leur charge utile ne porte **aucune donnée
personnelle** (ADR-0070 §3).

### 6. Les surfaces

`billing` expose `Contracts\PurchaseSubscriptionSupplement` et
`Contracts\ListSubscriptionSupplements`. Le graphe de modules **ne change
pas** : aucun autre module n'atteint `billing`, et `billing` n'atteint
personne de nouveau. La source unique de vérité du graphe
(`tests/Architecture/ModuleGraph.php`) est donc inchangée.

### 7. Ce qui n'est pas décidé ici

- **Pas de catalogue d'offres.** Le prix est déclaré par le projet au
  moment de l'achat, comme les lignes d'une facture manuelle. Un
  catalogue de packs par projet est le point de réévaluation quand le
  **portail** achète pour le client final : c'est le portail, et non
  l'application cliente, qui porte les gestes commerciaux du client
  final (ADR-0107, ADR-0114).
- **Pas de console.** La console opérateur suivra, comme le reste.
- **Pas de portail.** La route publique est un geste d'opérateur ; le
  parcours du client final appartient au portail (ADR-0075).

## Alternatives écartées

- **Une seconde subscription.** Rejeté : le compteur est indexé par
  `(projet, client, compteur, fenêtre)` (ADR-0074 §1) ; une seconde
  subscription ne s'y ajoute pas, elle ouvre un autre contrat — et le
  client perdrait son plan et sa période.
- **Une nouvelle version de plan qui porte plus de quota.** Rejeté : cela
  exige un changement de contrat (ADR-0072 §5), donc une nouvelle
  période et une nouvelle facture — exactement ce que le client ne veut
  pas.
- **Une fonctionnalité nouvelle du plan.** Rejeté : les fonctionnalités
  sont **nommées par le projet** (ADR-0071 §3) et un plan ne se modifie
  qu'en publiant une nouvelle version ; un supplément n'est pas une
  fonctionnalité, c'est une quantité.
- **Écrire `limit_value` à la main quand le pack est payé.** Rejeté : le
  compteur est une projection (ADR-0074 §1) ; la règle doit être dans le
  code, et une écriture manuelle ne serait ni atomique ni idempotente.
- **Un surcoût (overage) facturé à l'usage.** Rejeté : l'overage est une
  règle du plan sur le dépassement (ADR-0071 §3), pas un achat ; et le
  prestataire de paiement n'a aucun effet sur le quota (ADR-0074 §5).
- **Une facture manuelle créée par l'opérateur, sans lien avec le
  quota.** Rejeté : rien ne rattacherait la quantité achetée à la limite,
  et rien ne prouverait que l'argent a été payé.

## Conséquences

- `billing` gagne une table, un port, un adaptateur, deux contrats
  publics, deux routes, une application de service (le résolveur) et une
  application de service (l'activateur).
- ADR-0074 §3 est **amendé** sur un point : la limite du compteur n'est
  plus figée pour la fenêtre, c'est la **limite effective** qui est
  projetée ; la limite **du plan** y reste figée.
- Une consommation refusée reste **rejouable** (ADR-0074 §2) : le refus
  n'écrit rien, et la clé d'idempotence n'est pas consommée.
- La concurrence reste sûre : la limite effective ne peut qu'**augmenter**
  (un supplément `active` ne revient jamais en arrière), donc une
  admission concurrente qui lit une valeur périmée est au plus **plus
  stricte**, jamais plus laxiste. Le dépassement reste impossible.
- L'application cliente ne fait pas de commerce (ADR-0107, ADR-0114) :
  elle lit les droits, qui portent désormais la limite effective.
- **Point de réévaluation** : le catalogue d'offres de suppléments, quand
  le portail portera le geste d'achat du client final.

## Vérification

- Test unitaire (domaine) : les termes figés, la machine à trois états,
  le refus d'une quantité nulle et d'un prix négatif, la somme des
  allocations.
- Test d'intégration : un plan de 100 messages, les 100 consommés, un
  pack de 50 acheté **et payé** — la consommation suivante de 1 **passe**
  (limite 150), celle de 51 **échoue** ; la commande seule ne change rien ;
  le rejet d'un compteur absent du plan et d'un compteur sans plafond ;
  l'annulation de la facture annule le supplément.
- Test d'API : la **clé d'idempotence obligatoire** (400), l'achat
  rejoué n'achète qu'une fois, la même clé pour un autre corps est un
  conflit (409), la portée gate les deux routes (403), un compteur absent
  du plan est un problème 422, un contrat d'un autre projet est un 404.
- Non-régression : la suite `billing` (119 tests) et la suite `Contract`
  (20 tests, dont le catalogue des événements) restent vertes.
