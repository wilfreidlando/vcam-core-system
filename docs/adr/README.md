# Architecture Decision Records

Toute décision structurante est tracée ici : entrée d'une capacité dans le Core,
choix de contrat, exception, changement de règle transverse, écart assumé par
rapport aux spécifications. Un ADR de **refus** vaut autant qu'un ADR
d'acceptation : il alimente la liste négative et sert de jurisprudence
(gouvernance §12).

- Créer un ADR : partir de [0000-modele.md](0000-modele.md), ou utiliser la skill
  `nouvelle-adr`.
- Un ADR accepté ne se réécrit pas : on le **remplace** par un nouvel ADR et on
  met à jour le statut de l'ancien (« Remplacé par ADR-XXXX »).
- Numérotation continue, jamais réutilisée.

## Index

### Décisions de la spécification technique v1.0

| ADR | Décision | Statut |
| --- | -------- | ------ |
| [0001](0001-monolithe-modulaire.md) | Monolithe modulaire Laravel | Accepté — versions remplacées par 0017 |
| [0002](0002-contextes-bornes-packages-composer.md) | Contextes bornés en packages Composer internes, graphe vérifié | Accepté |
| [0003](0003-postgresql.md) | PostgreSQL, y compris pour les tests | Accepté — version remplacée par 0017 |
| [0004](0004-montants-entiers-minor-units.md) | Montants en entiers (minor units) + devise | Accepté |
| [0005](0005-ledger-double-entree-immuable.md) | Ledger double-entrée immuable, solde matérialisé | Accepté |
| [0006](0006-concurrence-wallet-verrou-pessimiste.md) | Concurrence wallet : verrou pessimiste + append atomique | Accepté |
| [0007](0007-ports-et-adaptateurs-providers.md) | Ports & Adapters pour paiement et notification | Accepté — précisé par 0026 |
| [0008](0008-outbox-transactionnel.md) | Transactional outbox | Accepté — précisé par 0024 |
| [0009](0009-webhooks-sortants-signes.md) | Webhooks sortants signés, retry, DLQ, replay | Accepté |
| [0010](0010-sanctum-et-passport.md) | Sanctum (opérateurs) + clés d'API et Passport (projets) | Accepté |
| [0011](0011-files-cache-verrous-valkey-horizon.md) | Files, cache et verrous : Horizon | Accepté — Redis remplacé par Valkey (0017) |
| [0012](0012-idempotency-key-obligatoire.md) | `Idempotency-Key` obligatoire sur les mutations monétaires | Accepté — précisé par 0025 |
| [0013](0013-observabilite-correlation.md) | Logs structurés, corrélation, OpenTelemetry | Accepté |
| [0014](0014-tenancy-base-partagee.md) | Tenancy par base partagée et scoping projet | Accepté |
| [0015](0015-rabbitmq-differe.md) | Bus de messages durable différé | Accepté |

### Décisions de la Phase 0 (2026-09-14)

| ADR | Décision | Statut |
| --- | -------- | ------ |
| [0016](0016-internals-hexagonaux.md) | Internals hexagonaux par module | Accepté |
| [0017](0017-baseline-versions-2026.md) | Baseline Laravel 13 / PHP 8.4 / PostgreSQL 18 / Valkey 8.1 | Accepté |
| [0018](0018-routes-possedees-par-les-modules.md) | Routes enregistrées par chaque module | Accepté |
| [0019](0019-docker-first.md) | Environnement Docker-first | Accepté |
| [0020](0020-shared-kernel-support.md) | Shared kernel `core/support` à manifeste fermé | Accepté |
| [0021](0021-wrapper-money.md) | Enveloppe `Money` au-dessus de brick/money | Accepté |
| [0022](0022-chaine-qualite.md) | Chaîne qualité et garde-fous automatisés | Accepté |
| [0023](0023-introspection-schema-garde-fous.md) | Garde-fous de schéma par introspection | Accepté |

### Décisions issues de la revue d'architecture (2026-09-14)

| ADR | Décision | Statut |
| --- | -------- | ------ |
| [0024](0024-outbox-table-unique-module-events.md) | Outbox : table unique possédée par `Events` | Accepté |
| [0025](0025-design-idempotence.md) | Design de l'idempotence HTTP | Accepté — implémentation Lot 3 (amendé par 0033) |
| [0026](0026-integration-providers-externes.md) | Principes d'intégration des providers externes | Accepté — construction Lot 3 |
| [0027](0027-read-models-et-acces-inter-modules.md) | Read models et accès inter-modules par `Contracts/` | Accepté |
| [0028](0028-versionnement-api-par-url.md) | Versionnement d'API par préfixe d'URL | Accepté — fenêtre de dépréciation proposée |

### Décisions du Lot 1 (2026-09-15)

| ADR | Décision | Statut |
| --- | -------- | ------ |
| [0029](0029-identity-depend-audit.md) | `identity` peut dépendre de `audit` | Accepté |
| [0030](0030-autoload-dev-des-modules-dans-le-root.md) | `autoload-dev` des modules doit être répété dans le `composer.json` racine | Accepté |

### Décisions post-Lot 1 (2026-09-15)

| ADR | Décision | Statut |
| --- | -------- | ------ |
| [0031](0031-socle-integration-en-parallele-du-lot-2.md) | Le socle d'intégration générique est avancé, en parallèle du Lot 2 | Accepté — amende 0026 |
| [0032](0032-outbox-ecriture-en-parallele-du-lot-2.md) | L'écriture de l'outbox est avancée, en parallèle du Lot 2 | Accepté |
| [0033](0033-conception-lot-2-money-core.md) | Conception Lot 2 : pas d'endpoint HTTP de mutation wallet, idempotence à deux niveaux, chart of accounts extensible | Accepté — amende 0025 |
| [0034](0034-socle-integration-scope-structure.md) | Socle d'intégration : scope, structure et compromis retenus | Accepté — précise 0026 et 0031 |
| [0035](0035-canonical-payment-status-canceled.md) | Étendre le vocabulaire canonique `PaymentStatus` avec `canceled` | Accepté — précise G6b |
| [0036](0036-paiement-confirme-credite-le-wallet-en-synchrone.md) | Un paiement confirmé crédite le wallet en appel synchrone, pas via le relais de l'outbox | Accepté |
