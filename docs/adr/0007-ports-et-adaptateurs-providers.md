# ADR-0007 — Ports & Adapters pour le paiement et la notification

- **Statut** : Accepté (spécification technique v1.0, ADR-07). Précisé par [ADR-0026](0026-integration-providers-externes.md).
- **Date** : 2026-09
- **Dérives concernées** : D4

## Contexte

Ajouter ou remplacer un provider (MTN MoMo, Orange Money, WhatsApp…) ne doit
jamais modifier ni redéployer une application consommatrice.

## Décision

- Ports `PaymentProvider` et `NotificationChannelProvider` (spécification
  technique §9.1–9.2), déclarés dans le `Domain` de leur module.
- Un adaptateur par provider dans
  `packages/<module>/src/Infrastructure/Provider/<Provider>/`.
- Un routeur choisit l'adaptateur selon la méthode ou le canal, la configuration
  du projet et de l'environnement, le pays, la disponibilité et une chaîne de
  fallback.
- Chaque adaptateur traduit les statuts bruts en statuts canoniques (§9.4) ; le
  brut est conservé (`raw_status`) pour l'audit.
- Garde-fous : **G6** (statut brut hors adaptateur), **G6b** (vocabulaire
  canonique figé), **G6c** (HTTP sortant centralisé).

## Conséquences

- Changer de provider = activer un adaptateur et changer la configuration du
  projet dans le Core Admin.
