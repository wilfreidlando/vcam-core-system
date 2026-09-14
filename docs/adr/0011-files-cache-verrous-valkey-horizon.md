# ADR-0011 — Files, cache et verrous : Horizon sur un backend compatible Redis

- **Statut** : Accepté (spécification technique v1.0, ADR-11). Backend remplacé par [ADR-0017](0017-baseline-versions-2026.md) (Redis → Valkey).
- **Date** : 2026-09
- **Dérives concernées** : D6

## Contexte

Files applicatives, cache et verrous distribués (utiles au-delà du verrou
pessimiste PostgreSQL pour d'autres besoins de coordination) sont nécessaires
dès le Lot 0.

## Décision

- Laravel Horizon pour la supervision des files, avec des files dédiées pour les
  webhooks et les notifications (isolation des pannes).
- Backend compatible Redis : Valkey ([ADR-0017](0017-baseline-versions-2026.md)),
  client `phpredis`.

## Conséquences

- `compose.yaml` : services `horizon` et `scheduler` séparés du service `app`, pour
  qu'un backlog de file ne dégrade jamais l'API de paiement.
