# ADR-0004 — Montants en entiers (minor units) associés à une devise

- **Statut** : Accepté (spécification technique v1.0, ADR-04)
- **Date** : 2026-09
- **Dérives concernées** : D6

## Contexte

En virgule flottante binaire, `0.1 + 0.2 ≠ 0.3`. Une colonne flottante transforme
l'erreur d'arrondi en argent qui ne se réconcilie pas, découvert après paiement.
Le MVP opère en XAF (exposant 0, pas de sous-unité) ; d'autres devises en ont.

## Décision

- Stockage : `amount_minor bigint` + `currency char(3)` référençant
  `currencies(code, exponent)`. Convention de nommage : suffixe `_minor`.
- Aucune colonne `float`, `double precision`, `real`, `numeric`, `decimal` ni
  `money` pour de l'argent — garde-fou **G5** (`tests/Schema/MoneyColumnsTest.php`).
- Calcul uniquement via `Core\Support\Money` ([ADR-0021](0021-wrapper-money.md)).
- Toute règle produisant une fraction d'unité déclare sa stratégie d'arrondi ;
  l'écart est porté par un compte de clearing du ledger, jamais perdu.

## Alternatives écartées

- **`numeric(19,4)`** : exact, mais deux représentations de l'argent coexisteraient
  et finiraient par diverger ; l'exposant de la devise ne serait plus la règle.

## Conséquences

- **À trancher avant le Lot 2** (spécification technique, annexe 21) : politique
  d'arrondi par devise et compte de clearing associé.
