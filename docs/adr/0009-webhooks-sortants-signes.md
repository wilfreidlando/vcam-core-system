# ADR-0009 — Webhooks sortants signés, retry, DLQ, replay

- **Statut** : Accepté (spécification technique v1.0, ADR-09)
- **Date** : 2026-09
- **Dérives concernées** : D5, D6

## Contexte

Une application consommatrice doit être notifiée de façon fiable des événements
qui la concernent, sans jamais recevoir un événement au nom d'un autre projet ni
pouvoir en falsifier un.

## Décision

- Livraison signée HMAC (`webhook_endpoints.secret`, chiffré au repos), en-tête
  de signature et timestamp.
- Idempotence côté consommateur via un `event_id` stable.
- Retry avec backoff exponentiel (`next_retry_at`), passage en `dead` (DLQ) après
  N échecs, replay contrôlé depuis le Core Admin.
- Abonnements déclarés par le projet (`webhook_endpoints.events`).

## Conséquences

- Module `Webhook`, dépend de `Identity` et `Events` uniquement (graphe de modules).
- Definition of Done du Lot 4 : un `payment.completed` livré avec retry
  démontrable en cas d'échec.
