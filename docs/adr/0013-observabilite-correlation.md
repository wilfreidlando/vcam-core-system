# ADR-0013 — Logs structurés, corrélation de bout en bout, OpenTelemetry

- **Statut** : Accepté (spécification technique v1.0, ADR-13) ; logs structurés et corrélation livrés en Phase 0
- **Date** : 2026-09
- **Dérives concernées** : D6, D7

## Contexte

Une opération sensible doit être explicable et corrélable de bout en bout : la
requête d'un produit, l'appel provider, les écritures ledger, l'événement
outbox, la livraison webhook, l'entrée d'audit.

## Décision

- Canal de log `structured` (JSON vers stdout, `Monolog\JsonFormatter`), par
  défaut en environnement déployé.
- Middleware `Core\Support\Http\Middleware\CorrelationId` : accepte
  `Correlation-Id` en entrée ou en génère un, l'ajoute au `Context` Laravel (donc
  à chaque entrée de log de la requête) et le renvoie en en-tête de réponse.
- Traces distribuées (OpenTelemetry) quand plusieurs modules interviennent :
  différé au Lot 4+, l'écosystème PHP OTel/Laravel restant peu mature (extension
  PECL requise, couverture Horizon incomplète).

## Conséquences

- Toute entrée d'audit ou tout événement porte le `correlation_id` de la requête
  qui l'a déclenché.
