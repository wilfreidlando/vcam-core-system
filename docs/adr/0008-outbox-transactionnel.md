# ADR-0008 — Transactional outbox

- **Statut** : Accepté (spécification technique v1.0, ADR-08). Précisé par [ADR-0024](0024-outbox-table-unique-module-events.md).
- **Date** : 2026-09
- **Dérives concernées** : D6

## Contexte

Un événement dispatché en dehors de la transaction qui change l'état peut être
perdu (rollback après dispatch) ou mentir (dispatch puis rollback : le
consommateur apprend une opération qui n'a jamais eu lieu).

## Décision

- L'événement est écrit dans `event_outbox` **dans la même transaction** que le
  changement d'état métier.
- Un relayeur (job Horizon) lit les lignes `published_at IS NULL`, dispatche vers
  les listeners internes et le module Webhook, puis marque `published_at`.
- Aucun module métier n'appelle directement `event()`, `dispatch()` ou
  `Event::dispatch()` — garde-fous **G8** (PHPat + règle PHPStan
  `NoDirectEventDispatchRule`) et **G8c** (preuve d'exécution : la ligne outbox
  survit ou disparaît avec la transaction).

## Alternatives écartées

- **Dispatch synchrone direct** : simple, mais viole l'atomicité état/événement.
- **Change Data Capture sur les tables métier** : robuste mais ajoute une
  dépendance infrastructurelle (Debezium ou équivalent) hors de proportion pour
  la volumétrie visée.

## Conséquences

- Voir [ADR-0024](0024-outbox-table-unique-module-events.md) pour le choix table
  unique vs table par module.
