# core-system — Enterprise Core System

Socle Laravel partagé (identity, ledger, wallet, paiement, frais, notification,
webhooks, audit) consommé par plusieurs produits. Quatre spécifications font
autorité, converties en Markdown dans `docs/specs/` (source `.docx` dans
`docs/specs/source/`) : fonctionnelle, technique, plan de delivery (8 lots),
gouvernance anti-dérive. Décisions structurantes dans `docs/adr/` (index :
[docs/adr/README.md](docs/adr/README.md)).

**Avant toute question sur ce dépôt, lire `docs/adr/README.md` et
`docs/architecture/garde-fous-inactifs.md`.** Ils répondent à « pourquoi c'est
comme ça » plus vite qu'explorer le code.

## Règles dures — jamais d'exception sans ADR

Ces règles sont vérifiées par CI (`make ci`) ; les violer fait échouer le build,
avec le message du garde-fou correspondant (voir
`docs/architecture/garde-fous-inactifs.md`) :

- **Aucune colonne monétaire en `float`/`double`/`numeric`/`decimal`.**
  Toujours `bigint` minor units + `currency char(3)`. (G5)
- **Aucun événement dispatché hors de l'outbox transactionnel**
  (`event()`, `dispatch()`, `Event::dispatch()` interdits dans
  `Domain/`/`Application/`). (G8)
- **`Domain/` et `Contracts/` sont du PHP pur** : aucune référence à
  Illuminate/Laravel/Symfony/Eloquent/PDO. (G3b)
- **Un module n'atteint un autre module que par son `Contracts/`**, jamais par
  son `Domain/`, `Application/` ou `Infrastructure/`. (G3c)
- **Le shared kernel `core/support` ne persiste rien** (pas d'Eloquent, `DB`,
  `Schema`, `Redis`). (G3d) — voir `packages/support/README.md` pour ce qui a
  le droit d'y vivre.
- **Le module `ledger` ne dépend d'aucun autre module.** (G2)
- **Seul `Core\Support\Integration` ouvre une connexion HTTP sortante** — aucun
  adaptateur ne fait `Http::post()` directement. (G6c)
- **Aucun statut brut provider (`'SUCCESSFUL'`, `'SUCCESS'`…) hors de son
  adaptateur** ; le cœur ne connaît que les statuts canoniques. (G6)
- **Toute route qui mute de l'argent exige `Idempotency-Key`.** (G7, actif dès
  Lot 2)

## Structure d'un module

```
packages/<module>/src/
  Domain/          PHP pur : entités, VO, événements de domaine, ports
  Application/     Use cases + DTO, orchestration transactionnelle
  Infrastructure/  Implémentations des ports (Eloquent, HTTP, providers)
  Presentation/     Contrôleurs, requests, resources, middleware
  Contracts/       Seule surface consommable par un autre module
```

Le graphe de dépendances autorisé entre modules a **une source unique** :
`tests/Architecture/ModuleGraph.php`. Modifier ce graphe = ADR + comité
d'architecture ([ADR-0002](docs/adr/0002-contextes-bornes-packages-composer.md)).

## Commandes

Tout passe par `make` (Docker-first, [ADR-0019](docs/adr/0019-docker-first.md)) :

| Commande | Fait |
| -------- | ---- |
| `make up` / `make down` | Démarre/arrête la stack |
| `make install` | Dépendances + clé d'app + migrations |
| `make ci` | Les six gates, exactement comme la CI |
| `make guard-test` | Injecte une vraie violation de chaque garde-fou actif, vérifie le rejet |
| `make arch` | Deptrac (modules + couches) + tests d'architecture |
| `make stan` | PHPStan niveau 10 + PHPat + règles maison |
| `make test` | Suite de tests en parallèle |
| `make shell` | Shell dans le conteneur app |

## Séquencement (plan de delivery, amendé par ADR-0031)

Lot 0 (garde-fous — fait, `fd2f515`) → **Lot 1 Identity — fait (`23de124`)** →
**en parallèle maintenant :** Lot 2 Money/Ledger (chemin critique, validation
du modèle par le comité avant code) **et** le socle d'intégration générique
(`Core\Support\Integration` — [ADR-0031](docs/adr/0031-socle-integration-en-parallele-du-lot-2.md),
ne dépend d'aucun des deux) → Lot 3 Payment/Fees (adaptateurs MTN/Orange +
orchestration ; démarre seulement une fois le Lot 2 terminé, puisqu'un paiement
confirmé doit pouvoir s'écrire au ledger) → Lot 4 Events/Webhooks → Lot 5
Notification → Lot 6 Core Admin → Lot 7 migration pilote. Détail de chaque lot :
[docs/specs/plan-delivery-mvp.md](docs/specs/plan-delivery-mvp.md) §4.

**Aucune capacité métier n'est implémentée avant que Lot 0 soit vert**
(`make ci` et `make guard-test` passants — c'est le cas).

## Gouvernance — avant d'ajouter quoi que ce soit au Core

Quatre portes, dans l'ordre (un seul « non » = ça reste dans le produit) :
transverse (≥2 produits réels) → générique (aucune règle métier spécifique) →
gain > coût → contractualisable. Détail :
[docs/specs/gouvernance-anti-derive.md](docs/specs/gouvernance-anti-derive.md) §4.
Un refus est aussi précieux qu'une acceptation — trace-le en ADR.

## Ce qui n'est PAS encore construit (ne pas supposer que ça existe)

- Aucune logique métier de ledger/wallet/paiement/notification (Lots 2–5).
- Identity/Audit sont livrés (Lot 1) : Organization/Project/ApiKey, middleware
  d'auth Project, `GET /v1/whoami`, module Audit minimal. Voir
  `packages/identity/README.md`.
- Module `payment` : **scaffoldé** — `PaymentStatus` canonique (avec
  `canceled` ajouté par ADR-0035), `PaymentGateway` port, `PaymentCommand`
  / `PaymentReceipt` / `PaymentAction` DTOs, `PaymentServiceProvider`.
  Pas d'Use Cases ni d'adapter concret avant Lot 3. Le module est
  dans le graphe Deptrac et reconnu par `CanonicalStatusTest`.
  Voir `packages/payment/README.md`.
- Idempotence : design figé ([ADR-0025](docs/adr/0025-design-idempotence.md)),
  code au Lot 2.
- Socle d'intégration providers (`Core\Support\Integration`) : **livré**
  ([ADR-0026](docs/adr/0026-integration-providers-externes.md) +
  [ADR-0031](docs/adr/0031-socle-integration-en-parallele-du-lot-2.md) +
  [ADR-0034](docs/adr/0034-socle-integration-scope-structure.md)).
  Comprend : `ExternalApiCaller` + pipeline complet (timeout/retry/breaker
  /auth/signing/correlation/redaction/kill switch), auth (ApiKey header+body,
  Basic, Bearer statique), signature HMAC-SHA256 sortante et entrante,
  circuit breaker in-memory, port `StatusMapper` (verrouille G6), port
  `TokenCache` (sans impl — l'impl Valkey viendra de l'app shell).
  Ce qui reste **hors** socle (Lot 3) : DSL de templates, catalogue
  d'endpoints, mappers spécifiques, adaptateurs concrets MTN/Orange, bus
  de saga, OAuth2 token-endpoint flow (qui sera ajouté quand MTN/Orange
  réels le demanderont).
- Laravel Boost (MCP) : incompatible avec Laravel 13 à ce jour
  (`illuminate/support` plafonné à `^12`) — non installé, à réessayer à sa
  prochaine version.

## Écarts assumés vs les spécifications

Voir [docs/adr/README.md](docs/adr/README.md), section « Décisions de la
Phase 0 » et « Décisions issues de la revue d'architecture ». En résumé :
baseline Laravel 13/PHP 8.4/PG 18/Valkey ([ADR-0017](docs/adr/0017-baseline-versions-2026.md)),
internals hexagonaux plutôt que l'arborescence plate illustrative du plan de
delivery ([ADR-0016](docs/adr/0016-internals-hexagonaux.md)), routes possédées
par chaque module plutôt qu'agrégées ([ADR-0018](docs/adr/0018-routes-possedees-par-les-modules.md)).
