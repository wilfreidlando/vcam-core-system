# ADR-0024 — Outbox : table unique possédée par `Events`

- **Statut** : Accepté
- **Date** : 2026-09-14
- **Dérives concernées** : D6

## Contexte

La revue d'architecture du 2026-09-14 (§3.5) proposait une table
`outbox_messages` par module, avec un relayeur unique parcourant toutes les
tables, au motif de la « localité du commit ». Le schéma proposé plaçait en
outre cette table dans `Support`.

## Décision

Une table unique `event_outbox`, possédée par le module `Events`
(spécification technique §6.8), utilisée par tous les modules à travers le
port `EventOutbox` exposé dans `Core\Events\Contracts`.

## Pourquoi la proposition de la revue est rejetée

1. **L'argument de « localité du commit » ne s'applique pas ici.** Dans un
   monolithe modulaire sur une base PostgreSQL unique, une opération qui touche
   `Wallet` et `Payment` s'exécute déjà dans **une seule transaction**. Que
   l'écriture outbox aille dans `wallet.outbox_messages` ou dans
   `event_outbox`, elle est physiquement dans la même transaction, sur la même
   connexion, dans la même base. Des tables séparées n'apportent aucune
   indépendance de commit — cet argument ne vaudrait que pour des bases
   distinctes par module, ce qui n'est pas l'architecture retenue
   ([ADR-0001](0001-monolithe-modulaire.md), [ADR-0014](0014-tenancy-base-partagee.md)).
2. **Contradiction interne à la revue.** Le schéma proposé plaçait
   `outbox_messages` comme « primitive partagée, possédée par `Support` ». La
   même revue (§3.3) affirme que `Support` doit être un manifeste fermé sans
   persistance — devenu le garde-fou **G3d**. Une table de queue est de l'état,
   pas une primitive technique.
3. **15 tables au lieu d'une** multiplient les migrations, les index, et le
   relayeur devrait interroger 15 tables au lieu d'une — sans bénéfice mesuré
   en contrepartie.

## Alternatives écartées

- **Table par module** : rejetée pour les raisons ci-dessus.
- **File Redis/Valkey directe sans table** : perd la garantie « même transaction
  que l'état », qui est tout l'intérêt du pattern outbox.

## Conséquences

- `Events` reste sans dépendance vers aucun autre module (règle du graphe).
- Le port `EventOutbox` (interface : `append(DomainEvent $event): void`) vit
  dans `Core\Events\Contracts` ; chaque module l'appelle dans sa propre
  transaction, jamais directement la table.
