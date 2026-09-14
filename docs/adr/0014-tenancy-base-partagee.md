# ADR-0014 — Tenancy par base partagée et scoping `organization_id`/`project_id`

- **Statut** : Accepté (spécification technique v1.0, ADR-14)
- **Date** : 2026-09
- **Dérives concernées** : D2, D3

## Contexte

Isolation entre organisations et projets nécessaire dès le Lot 1, sans le coût
opérationnel d'une base par client au démarrage.

## Décision

- Base partagée. Chaque table métier porte `organization_id` et/ou `project_id`.
- Isolation applicative par global scopes Eloquent, jamais désactivables
  silencieusement.
- Row-Level Security PostgreSQL envisageable en défense en profondeur
  (à activer si un incident de scoping applicatif se produit, jamais par
  anticipation pure).
- Extraction en base dédiée possible pour un gros client externe (exigence
  contractuelle), sans changer les contrats API.

## Conséquences

- Le comité d'architecture tranche l'activation du RLS ; ce n'est pas un
  prérequis du Lot 1.
