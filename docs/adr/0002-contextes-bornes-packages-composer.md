# ADR-0002 — Contextes bornés en packages Composer internes, graphe vérifié

- **Statut** : Accepté (spécification technique v1.0, ADR-02)
- **Date** : 2026-09 ; complété le 2026-09-14
- **Dérives concernées** : D1, D2

## Contexte

Il faut des frontières fortes entre capacités sans payer le coût opérationnel de
services séparés. Une frontière qui n'est que documentée finit contournée.

## Décision

- Chaque contexte borné est un package Composer interne `packages/<contexte>`
  (`core/<contexte>`, namespace `Core\<Contexte>`) référencé en path repository,
  avec ses migrations, ses routes et ses tests. Les packages internes sont
  requis en `*@dev` : un path repository se résout en `dev-main`.
- Le graphe de dépendances autorisé a **une seule source** :
  `tests/Architecture/ModuleGraph.php`. Il est reflété dans
  `deptrac-modules.yaml` et dans le `require` de chaque `composer.json` de module.
  Tout écart fait échouer la CI (G1, G1b).
- Le Ledger ne dépend d'aucun module (G2).
- Les 15 modules de la spécification technique §4 existent dès la Phase 0, y
  compris ceux hors MVP (`billing`, `storage`, `developer-platform`). Décision
  confirmée le 2026-09-14 malgré la remarque §3.1 de la revue d'architecture : le
  découpage vient de la spécification, pas d'une projection.

## Alternatives écartées

- **Namespaces dans `app/`** : aucune frontière vérifiable au niveau des
  dépendances déclarées, extraction plus coûteuse.
- **Créer les modules hors MVP à la demande** : défendable ; écarté pour garder le
  graphe complet visible dès le départ. Contrepartie : leurs dépendances sont
  hypothétiques et restent révisables par ADR avant leur premier code.

## Conséquences

- Ajouter un module = `ModuleGraph.php` + `deptrac-modules.yaml` + règle G3c + ADR ;
  sinon `ComposerGraphTest` échoue.
- Modifier le graphe est une décision du comité d'architecture.
