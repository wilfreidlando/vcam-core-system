# ADR-0015 — Bus de messages durable différé

- **Statut** : Accepté (spécification technique v1.0, ADR-15)
- **Date** : 2026-09
- **Dérives concernées** : D1 (sur-ingénierie prématurée)

## Contexte

Un bus durable (RabbitMQ ou équivalent) devient utile quand des modules sont
extraits en services indépendants. L'introduire avant cette extraction ajoute une
dépendance infrastructurelle sans bénéfice mesurable.

## Décision

Pas de bus durable au MVP. L'outbox transactionnel
([ADR-0008](0008-outbox-transactionnel.md)) et Horizon suffisent tant que tout
vit dans le même monolithe. Un bus est introduit **au moment de l'extraction**
d'un module en service, justifiée par la mesure — jamais par anticipation.

## Conséquences

Toute décision d'introduire un bus durable passe par le comité d'architecture et
un ADR qui documente la mesure ayant déclenché l'extraction.
