# ADR-0096 — `developer-platform` reste un scaffold jusqu'au lot 16

- **Statut** : Accepté — ratification par le comité d'architecture à venir
- **Date** : 2026-10-28
- **Décideurs** : le responsable du produit, sur proposition de l'audit de
  livraison du 2026-10-28 — **à confirmer par le comité d'architecture**
- **Amende** : ADR-0048 (les quatre modules hors périmètre), ADR-0068
  (`billing` et `developer-platform` entrent en production), ADR-0087
  (`developer-platform` sort du scaffold : registre, route, page)
- **Dérives concernées** : D1 périmètre · D7 organisationnelle

## Contexte

ADR-0048 avait mis `billing`, `storage`, `fees` et `developer-platform` hors
du périmètre de la version de production. ADR-0068 a amendé : `billing` et
`developer-platform` quittent le statut de scaffold, `fees` et `storage` y
restent. ADR-0087 (lot 16) précise que `developer-platform` tient un
registre machine des capacités, une route et une page — c'est-à-dire qu'il
**sort du scaffold au lot 16**.

Le code, lui, dit autre chose : `packages/developer-platform` ne contient
qu'un `ServiceProvider` et des fichiers `.gitkeep` — zéro route, zéro cas
d'usage, zéro migration, zéro test. Le module n'a jamais été construit.

`billing` a suivi le ratifié : il est en production depuis le lot 10, puis le
lot 11.

L'écart n'est donc pas une faute du code — c'est un ADR ratifié qui annonce
une capacité que rien ne porte. Un intégrateur qui lit ADR-0068 croira qu'il
existe un registre de capacités, une page de démarrage et un contrat
développeur : il n'existe pas.

## Décision

`developer-platform` **reste un scaffold** jusqu'au lot 16, qui le construit
(ADR-0086, ADR-0087). L'entrée en production décidée par ADR-0068 et
ADR-0087 est **différée au lot 16** : elle n'est opposable qu'à partir de ce
lot.

Tant que le module n'est pas construit :

- `docs/adr/README.md` le décrit comme un scaffold ;
- `docs/specs/inventaire-verifie-fusion.md` le liste parmi les scaffolds
  hors production ;
- `docs/specs/carte-des-capacites-produit.md` classe les capacités
  développeur en **◐ lot 16** — aucune ligne ne le présente comme livré ;
- aucun texte ne dit que `developer-platform` « entre en production ».

Un ADR accepté ne se réécrit pas : ADR-0048, ADR-0068 et ADR-0087 sont
amendés, pas réécrits.

## Alternatives écartées

- **Construire le module maintenant.** Le lot 16 n'a pas commencé ; le
  construire hors du chemin critique, sans la revue de sécurité que le plan
  exige pour un lot, serait devancer un lot sans le planifier. Écarté.
- **Supprimer le scaffold.** ADR-0002 a décidé que rien n'est retiré
  structurellement : le hors-périmètre est un statut, pas une suppression.
  Écarté.
- **Rétablir `billing` en scaffold.** Le module est en production, testé, et
  ses ADR (0070 à 0073, 0094, 0095) sont ratifiés et conformes au code.
  Écarté.

## Conséquences

- ADR-0048 et ADR-0068 restent valides sur le fond — `billing` entre en
  production — mais leur mention de `developer-platform` est différée et ne
  vaut engagement qu'au lot 16.
- Le lot 16 porte une charge supplémentaire : sortir `developer-platform` du
  scaffold (registre machine des capacités, route, page) est du travail de
  lot 16, pas de lot 15.
- La bascule (lot 17) n'est pas affectée : elle dépend de `portal`, pas de
  `developer-platform`.
- La fiche `packages/developer-platform/README.md` doit dire la même chose
  que cet ADR.

## Vérification

- `packages/developer-platform` ne contient aucune route, aucun cas d'usage,
  aucune migration, aucun test — vérifiable par `grep` sur l'arborescence.
- La carte des capacités classe les capacités développeur en ◐ lot 16, jamais
  en ●.
