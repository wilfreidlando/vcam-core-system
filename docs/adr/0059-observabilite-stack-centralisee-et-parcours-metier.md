# ADR-0059 — Observabilité : stack centralisée (Loki/Tempo/Prometheus/Grafana) et parcours métier des paiements

- **Statut** : Accepté
- **Date** : 2026-10-03
- **Décideurs** : utilisateur (plan d'implémentation, phase 2 ; décisions D1 et D5)
- **Dérives concernées** : D3 données/PII · D6 intégrité (traçabilité)

## Contexte

L'utilisateur veut « un endroit où tous les logs sont envoyés », suivre une requête
« du début jusqu'à la fin de la réponse envoyée », et voir pour un paiement « tout le
parcours du payload à la réponse finale, l'appel des webhooks ». État avant cette
décision :

- logs JSON sur la sortie standard de chaque conteneur, sans agrégation ;
- `correlation_id` perdu à l'outbox (`HandlePaymentStatusChangedUseCase` écrivait
  `null`), donc aucun lien entre un callback provider et la livraison webhook qui en
  découle ;
- ni trace distribuée, ni métrique, ni historique des tentatives de livraison (seule la
  dernière était conservée) ;
- les callbacks de paiement n'étaient persistés nulle part.

## Décision

**Deux couches, reliées par les mêmes identifiants.**

1. **Identifiants** (`Core\Support\Observability\TraceContext`) :
   - un `correlation_id` (en-tête `Correlation-Id`, validé, renvoyé au client) ;
   - un `trace_id` W3C (en-tête `traceparent` accepté et propagé), tous deux dans le
     `Context` Laravel, qui les ajoute à chaque log et les transporte dans les jobs ;
   - l'outbox les persiste (`event_outbox.correlation_id`, `trace_id`) et le relais les
     restaure avant de publier : le fil n'est plus rompu entre la requête et ses effets
     asynchrones ;
   - `project_id`, `environment` et `operator_id` sont ajoutés au contexte dès
     l'authentification.
2. **Couche technique** :
   - `LogHttpExchange` : une ligne `http.request.received` et une ligne
     `http.response.sent` par requête, corps rédigés et tronqués ;
   - `Tracer` maison et `OtlpHttpSpanExporter` (OTLP/JSON vers Alloy, puis Tempo), avec
     des spans serveur, SQL, HTTP sortant et job ; export après la réponse, jamais sur
     le chemin critique ;
   - `/metrics` (Prometheus, compteurs HTTP agrégés dans Valkey, jauges métier via des
     ports `Contracts` par module : `OutboxHealth`, `PaymentStats`, `NotificationStats`,
     `WebhookDeliveryStats`) ;
   - `/ready`.
   - Stack auto-hébergée (`compose.observability.yaml`, décision D1) : Alloy, Loki,
     Tempo, Prometheus et Grafana, avec les sources de données reliées entre elles
     (`trace_id` ⇄ logs), deux tableaux de bord et six règles d'alerte, le tout
     provisionné en code.
3. **Couche métier** : le **Parcours** d'un paiement.
   - `payment_timeline_entries` reçoit les étapes écrites par `PaymentJourney`
     (requête, échanges provider via `ExchangeJournal`, réponse, callbacks provider
     acceptés ou rejetés, réconciliation, transitions, statut ignoré, crédit wallet,
     événement publié, tentatives de `callback_url`) ;
   - `webhook_delivery_attempts` reçoit chaque tentative de livraison webhook (requête
     signée, signature masquée, réponse, durée, décision) ;
   - la console compose les deux via `Payment\Contracts\GetPaymentTimeline` et
     `Webhook\Contracts\GetWebhookDeliveryDetails`, sans nouvelle arête dans le graphe
     de modules (G3c intact) ;
   - rétention de 90 jours (décision D5 : données personnelles visibles aux opérateurs
     autorisés, secrets toujours masqués).

## Alternatives écartées

- **SDK OpenTelemetry avec auto-instrumentation** : exige l'extension PECL
  `opentelemetry`, absente de l'image. Les quatre points d'instrumentation utiles
  tiennent en peu de code, et le format OTLP standard permet de basculer plus tard sans
  rien changer côté Tempo ni Grafana.
- **Parcours reconstruit depuis Loki** : les logs ne sont pas une source de vérité
  métier (ils sont échantillonnables et purgeables indépendamment), et une page opérateur
  ne doit pas dépendre de la disponibilité de la stack de supervision.
- **Table de parcours unique, transverse** : il aurait fallu un nouveau module, donc une
  modification du graphe. Chaque module garde ses propres étapes, et c'est la console qui
  les compose.

## Conséquences

- Deux nouvelles tables (`payment_timeline_entries`, `webhook_delivery_attempts`) et une
  colonne (`event_outbox.trace_id`), toutes purgées par `model:prune`.
- `PaymentStatusChangeHandler::handle()` reçoit une `source` (`provider_callback` ou
  `reconciliation`).
- Une initiation refusée par le provider (exception avant création du paiement) n'a pas
  de parcours, puisqu'il n'existe pas de paiement auquel l'attacher. Elle reste visible
  dans les logs, sous son `correlation_id`.
- Exploitation : voir [docs/operations/observabilite.md](../operations/observabilite.md).
