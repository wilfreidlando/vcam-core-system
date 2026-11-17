# ADR-0126 — La clé publique d'un prestataire peut servir plusieurs projets

- **Statut** : Accepté
- **Date** : 2026-11-15
- **Décideurs** : commanditaire (« je veux que dans le module application provider on puisse enlever l'unicité sur les clés car on peut bien enregistrer la même clé pour plusieurs application provider », « le but est que je puisse enregistrer une même clé même dans des projets différents, pas dans le même projet »), comité d'architecture
- **Précise** : ADR-0051 §1 (une application MyCoolPay par Project), ADR-0066 (une application provider par (projet, provider))
- **Amende** : ADR-0051 (le caractère unique de `public_key`), ADR-0060 §4

## Contexte

`payment_provider_applications` porte les identifiants marchands d'un
projet chez un prestataire (MyCoolPay) : une paire clé publique / clé
privée. Deux index uniques la bornaient :

| Index | Règle | Raison d'origine |
| --- | --- | --- |
| `UNIQUE(project_id, provider_code)` | un projet, une application par prestataire | « chaque application a ses clés » (ADR-0066) |
| `UNIQUE(provider_code, public_key)` | une clé publique, un projet | le `public_key` était **la clé de résolution** du callback entrant (ADR-0051) |

Le second index est une **contrainte d'attribution**, pas une contrainte
de saisie. Le callback MyCoolPay porte le champ `application` (la clé
publique) ; le Core le résolvait en une application, donc en un projet, et
n'acceptait le callback que si la signature MD5 correspondait à la clé
privée **de ce projet**.

Ce modèle suppose qu'une application marchande appartient à un seul
locataire. Le commanditaire a posé l'autre cas : *« on peut bien
enregistrer la même clé pour plusieurs application provider »*, puis a
précisé le but — *« enregistrer une même clé même dans des projets
différents, pas dans le même projet »*. Concrètement : une plateforme qui
encaisse pour plusieurs entités avec **un seul compte marchand** chez le
prestataire. Les tenants ont alors la **même** clé publique, et le refus
« cette clé publique est déjà utilisée par un autre projet » les bloque
pour de bon.

## Décision

**Décision 1 — `UNIQUE(provider_code, public_key)` est retiré.**

Une même paire de clés peut être enregistrée par plusieurs projets. La
migration `2026_11_15_090000_drop_provider_application_public_key_unique`
supprime l'index ; il n'est pas recréé — un index **simple** sur la même
paire le remplace, car la résolution d'un callback la cherche à chaque
appel.

**Décision 2 — `UNIQUE(project_id, provider_code)` reste.**

Un projet garde **une application par prestataire** (ADR-0066) : dans un
même projet, la même clé ne s'enregistre pas deux fois chez le même
prestataire. La console dit déjà cela (« Si l'application existe déjà pour
ce prestataire, ses clés sont remplacées »), et le geste « Modifier »
reste le moyen de faire tourner les clés.

**Décision 3 — la clé publique ne route plus un callback ; le paiement le
fait.**

Un callback entrant est résolu ainsi :

1. la clé publique du payload donne **la liste** des applications
   enregistrées avec cette clé (elle peut en avoir plusieurs) ;
2. le **paiement nommé par le callback** (`transaction_ref`) dit de quel
   projet il s'agit ; les candidats sont **bornés à ce projet** ;
3. la signature MD5 est vérifiée contre la clé privée des candidats
   restants. Si aucune ne convient, le callback est refusé (403), comme
   aujourd'hui.

Un callback portant une clé partagée n'est donc accepté que pour un
paiement d'un projet qui a enregistré cette clé. Une clé tenue par un
autre locataire ne peut pas solder le paiement d'un tiers.

**Décision 4 — le refus disparaît, l'avertissement reste.**

L'exception `ProviderApplicationPublicKeyAlreadyUsed` est **supprimée** :
plus rien ne refuse l'enregistrement. Le message qui nommait le projet
titulaire disparaît avec elle — il n'a plus d'objet, et il ne nommait de
toute façon qu'un projet que le commanditaire pouvait voir.

Ce qui reste : un **avertissement au journal** quand une clé est
enregistrée par un projet alors qu'un autre projet l'a déjà
(`payment.provider_application.shared_public_key`). Il porte le nombre de
projets concernés, **jamais leur identité** (ADR-0069 §2, S-01).

**Décision 5 — le risque résiduel est dit, pas caché.**

Une paire de clés **partagée** — même clé privée — donne à ses détenteurs
la possibilité de forger un callback pour les transactions de l'autre
projet : la signature serait valide et le Core l'attribuerait au projet du
paiement nommé. Le Core ne peut pas distinguer « deux projets qui
partagent le même compte marchand » de « deux projets qui se font
mutuellement confiance » : c'est un fait d'exploitation, pas une donnée.

C'est le prix d'ADR-0126, et il est assumé. Le garde-fou technique reste :
un callback est toujours rattaché au paiement qu'il nomme, jamais à la
seule clé.

## Conséquences

- Une plateforme qui encaisse pour plusieurs entités avec un compte
  marchand unique peut enregistrer cette clé pour chacun de ses projets.
- Le refus « cette clé publique est déjà utilisée par un autre projet »
  n'existe plus ; l'opérateur voit un avertissement au journal quand il
  partage une clé.
- La résolution d'un callback entrant est un peu plus coûteuse (une liste
  au lieu d'une ligne), et elle est bornée par le projet du paiement.
- `ProviderApplicationRepository::findByProviderAndPublicKey()` devient
  `findAllByProviderAndPublicKey()` : une liste, plus une application.
- Les données existantes ne sont pas touchées : aucun index unique n'est
  violé par des lignes déjà présentes.

## Alternatives écartées

- **Garder l'index unique** : c'est le statu quo, et il refuse
  l'enregistrement même lorsque l'exploitation est légitime (un compte
  marchand partagé). L'empêchement n'est pas une protection : il n'a
  d'effet que sur un projet qui n'a rien fait de mal.
- **Retirer aussi `UNIQUE(project_id, provider_code)`** : un projet
  pourrait alors enregistrer deux applications chez le même prestataire.
  Le commanditaire a écarté ce cas (*« pas dans le même projet »*), et la
  règle d'ADR-0066 reste.
- **Résoudre le callback par « la première application trouvée »** :
  l'attribution deviendrait arbitraire — le callback d'un projet pourrait
  être authentifié avec la clé d'un autre, et solder son paiement. Le
  routage par le paiement nommé ne laisse pas ce vide.

## Vérification

- `packages/payment/tests/Unit/Application/UseCase/RegisterProviderApplicationTest.php`
  — deux projets enregistrent la même clé ; l'avertissement au journal
  porte un compte, jamais l'identité de l'autre locataire.
- `packages/payment/tests/Integration/Presentation/Http/Controllers/MyCoolPayWebhookControllerTest.php`
  — sur une clé partagée, un callback signé par la clé d'un autre projet
  est refusé (403) ; signé par la clé du projet du paiement, il passe.
