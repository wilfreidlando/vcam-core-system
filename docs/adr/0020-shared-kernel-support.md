# ADR-0020 — Shared kernel `core/support` à manifeste fermé

- **Statut** : Accepté ; renforcé le 2026-09-14 suite à la revue d'architecture (§3.3)
- **Date** : 2026-09-14
- **Dérives concernées** : D1, D2

## Contexte

Certaines primitives (horloge, réponses d'erreur, corrélation) sont utiles à
tous les modules et ne portent aucune règle métier. Sans les isoler, chaque
module les réimplémenterait légèrement différemment (dérive D2 par duplication).
Mais un package que tout le monde peut utiliser, et qui n'a lui-même aucune
dépendance à respecter, est structurellement le candidat le plus probable pour
devenir un fourre-tout — signalé explicitement par la revue d'architecture du
2026-09-14.

## Décision

- `packages/support` (`core/support`) : tout module peut en dépendre ; lui ne
  dépend d'aucun module (seulement de PHP et, à la marge, de bibliothèques
  techniques comme `brick/money`).
- Son contenu est un **manifeste fermé**, documenté et tenu à jour dans
  `packages/support/README.md` : horloge (`Clock`/`SystemClock`), énumération
  d'environnement, réponses RFC 7807, corrélation, et — une fois livrés —
  l'enveloppe `Money` ([ADR-0021](0021-wrapper-money.md)), l'idempotence
  ([ADR-0025](0025-design-idempotence.md)) et l'appelant d'intégration HTTP
  ([ADR-0026](0026-integration-providers-externes.md)).
- **Aucune persistance** : ni Eloquent, ni `DB`, ni `Schema`, ni `Redis`. Une
  primitive qui a besoin de stocker déclare un port ; le module qui l'utilise en
  premier fournit l'implémentation. Garde-fou **G3d**
  (`tools/PhpStan/Architecture/SharedKernelStaysTechnical.php`).
- Une primitive n'entre dans `Support` que si elle est utilisée par au moins
  deux modules, sans règle métier ni vocabulaire provider — même porte n°1/n°2
  que pour une capacité entrant dans le Core, appliquée en interne.

## Alternatives écartées

- **Pas de shared kernel, duplication assumée** : chaque module réimplémente sa
  propre corrélation et son propre format d'erreur ; incohérence garantie à
  terme.
- **Shared kernel sans manifeste** (statu quo avant le 2026-09-14) : c'est
  précisément ce que la revue a signalé comme risqué.

## Conséquences

- Toute PR qui ajoute une classe à `Support` doit mettre à jour son README **et**
  le présent ADR si la catégorie de primitive est nouvelle.
- G3d interdit structurellement le cas le plus probable de dérive (persistance
  cachée), sans dépendre de la vigilance en revue.
