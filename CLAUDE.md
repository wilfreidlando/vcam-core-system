# core-system — Enterprise Core System

Socle Laravel partagé (identity, ledger, wallet, paiement, frais, notification,
webhooks, audit) consommé par plusieurs produits. Quatre spécifications font
autorité, converties en Markdown dans `docs/specs/` (source `.docx` dans
`docs/specs/source/`) : fonctionnelle, technique, plan de delivery (8 lots),
gouvernance anti-dérive. Décisions structurantes dans `docs/adr/` (index :
[docs/adr/README.md](docs/adr/README.md)).

**Avant toute question sur ce dépôt, lire
[docs/architecture/vue-ensemble.md](docs/architecture/vue-ensemble.md)**
(vue globale avec schémas : modules, topologie d'exécution, anatomie d'une
requête, garde-fous), **`docs/adr/README.md` et
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
- **Toute route qui mute de l'argent exige `Idempotency-Key`.** (G7, actif
  depuis `POST /v1/payments`, Lot 3)

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
**Lot 2 Money/Ledger — fait (`8d4a969`)** : Ledger double-entrée (contraintes
PostgreSQL réelles, pas seulement applicatives), Wallet crédit/débit,
Parties à création paresseuse, écriture de l'outbox (ADR-0032). Voir
[ADR-0033](docs/adr/0033-conception-lot-2-money-core.md) pour les décisions
de conception. En parallèle, livré aussi : le socle d'intégration générique
(`Core\Support\Integration` — [ADR-0031](docs/adr/0031-socle-integration-en-parallele-du-lot-2.md)
+ [ADR-0034](docs/adr/0034-socle-integration-scope-structure.md)) → **Lot 3
Payment — orchestration livrée** (`channel` canonique, routage par Project,
état propre à `Payment`, idempotence HTTP G7, webhook → statut → crédit
wallet optionnel — [ADR-0038](docs/adr/0038-conception-lot-3-payment-channel-routage-etat-callback.md)) :
un paiement, par défaut, n'est qu'un encaissement au nom du système, sans
Party ni Wallet touchés ; le crédit d'un wallet est une instruction
explicite fournie par l'appelant à l'initiation du paiement, jamais déduite
du seul succès du paiement
([ADR-0037](docs/adr/0037-credit-wallet-jamais-automatique-composition-explicite.md)),
exécuté en synchrone, même transaction, quand elle est fournie
([ADR-0036](docs/adr/0036-paiement-confirme-credite-le-wallet-en-synchrone.md)).
Reste pour un Lot 3 complet : le moteur **Fees**, les adaptateurs MTN/Orange
directs (seul le mécanisme de routage existe, pas d'adaptateur concret pour
eux) → **Lot 4 Events/Webhooks — livré** : relais unique de l'outbox
(`Core\Events\Infrastructure\Relay\OutboxRelay`, planifié chaque minute) +
module `Webhook` (endpoints par Project, livraison signée HMAC,
retry/backoff persisté via Horizon, DLQ, replay CLI —
[ADR-0040](docs/adr/0040-conception-lot-4-relais-outbox-webhooks-sortants.md))
→ **Lot 5 Notification — orchestration livrée**
(fallback synchrone ordonné, `NotificationStatus` canonique, API
self-service `POST /v1/notifications/{text,image,file}` — un endpoint par
type de contenu, contenu fourni en ligne, pas de template pré-enregistré
— [ADR-0041](docs/adr/0041-conception-lot-5-notification-orchestration-sans-adaptateur.md),
[ADR-0043](docs/adr/0043-notifications-endpoints-par-type-de-contenu-sans-template.md)),
adaptateur WhatsApp concret livré ensuite : WAHA auto-hébergé (envoi
texte/image/fichier + liaison de téléphone QR/pairing-code/screenshot,
sans aucune persistance de session côté Core —
[ADR-0042](docs/adr/0042-whatsapp-waha-adapter-channel-session-minimal-surface.md))
→ **Lot 6 Core Admin — deux tranches livrées** : opérateurs/RBAC/MFA
(`Core\Identity\OperatorRole`, Sanctum, `pragmarx/google2fa`), recherche
d'audit (`Core\Audit\Contracts\AuditQuery`), liste + rejeu de la DLQ
webhook, réconciliation paiement par `checkStatus()` convergeant sur
`PaymentStatusChangeHandler` existant
([ADR-0044](docs/adr/0044-conception-lot-6-core-admin-operateurs-rbac-audit-dlq-reconciliation.md)),
puis listing générique projects/payments/wallets/notifications, un port
`List*` par module réutilisant les read models existants
([ADR-0045](docs/adr/0045-lot-6-listing-generique-admin.md)), puis une revue
de code post-livraison corrigeant une course webhook/réconciliation (verrou
de ligne), l'expiration des jetons opérateur, l'audit des actions admin, le
throttle login/MFA, l'anti-rejeu TOTP et la validation d'UUID sur 5 endpoints,
entre autres
([ADR-0046](docs/adr/0046-lot-6-revue-de-code-correctifs.md)) —
reste pour un Lot 6 complet : règles de frais, billing, monitoring opérationnel
→ Lot 7 migration pilote. Détail de chaque lot :
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

- Frais (reste du Lot 3) : pas construit.
- Le paiement bout-en-bout (channel → routage → webhook → crédit wallet
  optionnel) est livré (détail ci-dessous), mais **le crédit d'un wallet
  n'est jamais automatique** (ADR-0037) : un paiement est un encaissement
  pur sauf instruction explicite de l'appelant à l'initiation. Ne pas
  supposer qu'un paiement réussi implique un wallet crédité — vérifier
  que `wallet_recipient_external_ref` a été fourni.
- Module `notification` : **orchestration Lot 5 livrée** — voir
  [ADR-0041](docs/adr/0041-conception-lot-5-notification-orchestration-sans-adaptateur.md)
  et [ADR-0043](docs/adr/0043-notifications-endpoints-par-type-de-contenu-sans-template.md).
  `NotificationChannelProvider` étend le même `ProviderGateway` générique
  que `PaymentGateway` (même registre, même détection de codes dupliqués) ;
  `NotificationProviderRouter::resolveProviders()` retourne une **liste**
  ordonnée (primaire + fallbacks) — la cascade de fallback partagée
  (`NotificationSendCascade`) bascule immédiatement vers le provider
  suivant à l'échec, jamais un retry différé (ça, c'est le rôle du
  Webhook du Lot 4). Chaque tentative est tracée dans
  `notification_deliveries`. **Pas de template pré-enregistré**
  (ADR-0043 — `notification_templates` supprimée) : trois endpoints, un
  par type de contenu — `POST /v1/notifications/text`,
  `/image`, `/file` — prennent le contenu directement dans la requête ;
  la règle WhatsApp 24h/template-approuvé n'est pas câblée dans le Core,
  elle appartiendrait à un futur adaptateur qui l'exige (l'API Business
  officielle, par exemple — WAHA ne l'exige pas).
  `HandleNotificationStatusChangedUseCase` existe et est testé, appelé
  par `WahaWebhookController` (`POST /webhooks/whatsapp-waha`). **Rien ne
  déclenche une notification automatiquement** — les endpoints `send-*`
  sont la seule porte d'entrée, même en réaction à un
  `payment.status_changed` (même raisonnement qu'ADR-0037 : pas de
  composition implicite entre modules).
  Adaptateur concret livré : WhatsApp via WAHA auto-hébergé
  ([ADR-0042](docs/adr/0042-whatsapp-waha-adapter-channel-session-minimal-surface.md)) —
  `WahaAdapter` implémente `NotificationChannelProvider` (send
  texte/image/fichier, `OutboundMessage::body` porte le contenu brut
  fourni par l'appelant, interprété par l'adaptateur) **et** un nouveau
  port `ChannelSessionProvider` (QR/pairing-code/screenshot,
  `/v1/notification-channel-sessions/{channel}/{sessionRef}/*`) — le
  **Core ne persiste rien sur une session** : `sessionRef` est fourni par
  l'appelant à chaque requête et transmis tel quel au provider, qui reste
  l'unique source de vérité sur son état. Le numéro de téléphone du
  destinataire voyage dans `variables['phone_number']`, jamais dans
  `to_party_ref`/`recipientRef` (G4). Ne pas supposer qu'un autre canal
  (SMS, email) est routable avant qu'un adaptateur soit tagué
  `provider.gateway` pour lui. Les contrôleurs propres à un provider
  vivent sous `Presentation\Http\Controllers\<Provider>\` (ex. `Waha\`)
  — le résolveur de tags OpenAPI (`AppServiceProvider`) en tire
  automatiquement un second tag, donc les endpoints WAHA apparaissent
  sous `Notification` + `Waha` dans la doc, les endpoints génériques
  seulement sous `Notification`.
- Module `webhook` et relais outbox : **Lot 4 livré** — voir
  [ADR-0040](docs/adr/0040-conception-lot-4-relais-outbox-webhooks-sortants.md).
  `Core\Events\Infrastructure\Relay\OutboxRelay` est le seul lecteur
  physique d'`event_outbox` (planifié chaque minute, `events:relay`) ; il
  republie chaque ligne comme `Core\Events\Contracts\OutboxEventPublished`,
  un événement Laravel interne auquel `webhook` s'abonne
  (`FanOutRelayedEventListener`, `ShouldQueue`) — jamais de deuxième
  lecteur de l'outbox. `POST/GET /v1/webhook-endpoints` (self-service,
  contrairement aux clés d'API qui restent CLI-only), secret HMAC généré
  une fois et chiffré au repos (`Crypt`, jamais haché — il doit être relu
  en clair pour signer chaque livraison). La livraison HTTP ne passe **pas**
  par `ExternalApiCaller` (conçu pour un catalogue de providers, pas une
  URL Project dynamique avec retry sur des heures) : elle compose
  directement `Core\Support\Integration\Transport\HttpTransport` +
  `Signing\HmacSha256RequestSigner`, toujours le seul point de sortie HTTP
  (G6c). Retry persisté via `DeliverWebhookJob::release()` (Horizon), DLQ
  après épuisement, replay via `webhook:replay` (CLI) **et** depuis le Lot 6
  via `POST /v1/admin/webhook-deliveries/{id}/replay` (ADR-0044) — les deux
  convergent sur le même `Contracts\ReplayWebhookDelivery` +
  `Contracts\QueueWebhookDelivery`. Ce qui reste hors périmètre :
  `notification`/`billing` ne consomment pas encore `OutboxEventPublished`
  (Lot 5+).
- Module `admin` : **deux tranches du Lot 6 livrées** — voir
  [ADR-0044](docs/adr/0044-conception-lot-6-core-admin-operateurs-rbac-audit-dlq-reconciliation.md)
  et [ADR-0045](docs/adr/0045-lot-6-listing-generique-admin.md).
  Nouvelle strate d'authentification « Plan 1 » (spec §5.1), parallèle au
  plan Project/ApiKey : `Core\Identity\OperatorRole` (enum PHP fixe, pas
  le schéma dynamique roles/permissions de la spec — écart assumé, YAGNI),
  Sanctum (jetons API purs, pas de mode SPA/cookie), MFA obligatoire dès
  la première connexion via `pragmarx/google2fa` (pas de rendu QR serveur
  — plateforme API-only). Flux : `POST /v1/operators/login` →
  `mfa/enroll` (1ère connexion) ou `mfa/verify` (connexions suivantes) →
  jeton Sanctum ; création d'opérateur CLI-only
  (`identity:create-operator`, pas de self-service). Quatre capacités
  exposées sous `/v1/admin/*` (middleware `operator.auth`, jamais
  `project.auth` — et donc jamais `idempotency`, câblée sur
  `ProjectContext`) : `GET /audit-log` (nouveau port
  `Core\Audit\Contracts\AuditQuery` — **`admin` ne lit jamais `audit_log`
  directement**, malgré ce qu'un commentaire Lot 1 suggérait à tort ;
  G3c/ADR-0027 s'appliquent à `admin` sans exception), `GET
  /webhook-deliveries/dead` + `POST .../replay` (réutilise
  `ReplayWebhookDelivery` intact), `POST /payment-reconciliation-runs`
  (déclenche `Core\Payment\Contracts\ReconcileStuckPayments`, aussi
  planifié via `admin:reconcile-stuck-payments` toutes les 5 minutes).
  Deuxième tranche (ADR-0045) : listing générique, un port `List*` par
  module (`Identity\Contracts\ListProjects`, `Payment\Contracts\ListPayments`,
  `Wallet\Contracts\ListWallets`, `Notification\Contracts\ListNotifications`)
  — `GET /v1/admin/projects` (optionnellement filtré par
  `organization_id`), puis `GET /v1/admin/projects/{projectId}/{payments,
  wallets,notifications}` (permissions `projects:read`/`payments:read`/
  `wallets:read`/`notifications:read`, réparties par rôle selon la spec
  §8.1). Réutilise les read models existants (`PaymentView`,
  `NotificationView`) plutôt que d'en dupliquer la forme ; un filtre de
  statut en query param est une chaîne brute dans le DTO `Contracts`
  (jamais l'enum canonique, qui vit hors de `Contracts/` — G3c). Aucune
  route `POST`, donc hors du champ G7/`idempotency`. Convention de
  pagination (`page`/`per_page`, `{data, meta}`) reprise telle quelle
  d'`AuditQuery` (ADR-0044). Hors périmètre des deux tranches : règles de
  frais, billing, monitoring opérationnel/OTel, gestion self-service des
  opérateurs.
- Identity/Audit sont livrés (Lot 1) : Organization/Project/ApiKey, middleware
  d'auth Project, `GET /v1/whoami`, module Audit minimal. Voir
  `packages/identity/README.md`. Depuis le Lot 6 (ADR-0044), `identity`
  porte aussi le plan « Plan 1 » opérateurs/RBAC/MFA (`Operator`,
  `OperatorRole`, Sanctum) — voir le point `admin` ci-dessous ; `audit`
  porte un port de lecture (`AuditQuery`) en plus du port d'écriture
  historique (`AuditLogger`).
- Ledger/Wallet/Parties sont livrés (Lot 2) : ledger double-entrée avec
  contraintes PostgreSQL réelles (triggers de solde et d'immutabilité),
  wallets crédit/débit (aucun endpoint HTTP de mutation — ADR-0033 §1),
  parties à création paresseuse. Voir `packages/ledger/README.md`,
  `packages/wallet/README.md`, `packages/parties/README.md` — et
  [ADR-0033](docs/adr/0033-conception-lot-2-money-core.md).
- Module `payment` : **orchestration Lot 3 livrée** — voir
  [ADR-0038](docs/adr/0038-conception-lot-3-payment-channel-routage-etat-callback.md)
  et [ADR-0039](docs/adr/0039-payment-gateway-registry-typed.md). Le champ
  `channel` canonique (`mtn_momo`, `orange_money`, `card`…), distinct du
  `provider` interne jamais exposé ; `ConfigPaymentProviderRouter` résout
  `(channel, Project) → provider` ; l'agrégat `Payment` possède son propre
  état (il sait quel provider le traite, le statut et les webhooks entrants
  n'ont donc jamais à deviner) ; `StartPaymentUseCase` (`POST /v1/payments`),
  `GetPaymentStatusUseCase` (`GET /v1/payments/{id}`, lit l'état du Core
  seulement, pas de réconciliation live avec le provider) et
  `HandlePaymentStatusChangedUseCase` (webhook → statut → crédit wallet
  optionnel, transactionnel, idempotent au rejeu). Adaptateur concret livré :
  MyCoolPay (`initiate`, `submitAction`, `checkStatus`, webhook).
  `PaymentGatewayRegistry` détecte les codes provider dupliqués au boot
  plutôt que d'écraser silencieusement (ADR-0039). Depuis le Lot 6
  (ADR-0044), `payment` expose aussi `Contracts\ReconcileStuckPayments` —
  une réconciliation par `checkStatus()` pour les webhooks manqués,
  déclenchée par `admin` (planifiée ou à la demande), convergeant sur
  `HandlePaymentStatusChangedUseCase` sans logique dupliquée. Reste à
  construire : le moteur Fees, les adaptateurs MTN/Orange directs (seul
  le mécanisme de routage existe). Voir `packages/payment/README.md`.
- Idempotence à deux niveaux ([ADR-0025](docs/adr/0025-design-idempotence.md),
  amendé par [ADR-0033](docs/adr/0033-conception-lot-2-money-core.md) §2) —
  **les deux niveaux sont livrés** : le niveau ledger
  (`idempotency_key` + `wasAlreadyPosted`) depuis le Lot 2, le niveau HTTP
  (`RequireIdempotencyKey` middleware, `Idempotency-Key` obligatoire sur
  `POST /v1/payments`, G7 actif) depuis le Lot 3.
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
