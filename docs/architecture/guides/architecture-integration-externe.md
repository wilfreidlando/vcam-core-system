# Architecture d'intégration des services externes

**Destinataires** : agent en charge des Lots 3+ (Payment, Notification, Webhook, Storage), Tech Lead pour validation.
**Statut** : recommandation à valider **avant Lot 3**. Les décisions ici figent la façon dont tous les providers seront branchés pour les dix prochaines années.

> **⚠ Statut au 2026-09-15 : partiellement retenu — l'[ADR-0026](../../adr/0026-integration-providers-externes.md)
> et son amendement [ADR-0031](../../adr/0031-socle-integration-en-parallele-du-lot-2.md) prévalent sur ce guide.**
>
> **Séquencement révisé (ADR-0031)** : le socle générique (pilier 2 —
> `ExternalApiCaller`, stratégies d'auth, retry/timeout/circuit breaker,
> signature, kill switch, vérification de signature webhook) est construit
> **maintenant, en parallèle du Lot 2**, pas après. Le DSL déclaratif
> (piliers 3-4-5 — templates, catalogue d'endpoints, mappers spécifiques) et
> les adaptateurs concrets restent au Lot 3, inchangés.
>
> **Retenu** : anti-corruption layer, statuts canoniques, appelant HTTP sortant unique
> (garde-fou G6c actif), registre de providers sans `match`, kill switch par provider,
> pipeline des callbacks entrants (signature d'abord, déduplication, mapping canonique),
> tests de contrat par adaptateur.
>
> **Rejeté** : construire ce framework au Lot 0 (il sera extrait de MTN **et** Orange au
> Lot 3) · le mode `idle` qui renvoie un **succès simulé** hors sandbox (un produit croirait
> à un paiement qui n'a pas eu lieu) · la table générique `support.adapter_logs` (le
> shared kernel ne persiste rien ; les tentatives sont tracées par le module propriétaire)
> · l'exposition des logs d'appel provider aux applications (casse l'invariant n°1 de ce
> guide) · le DSL de templates et l'`EndpointCatalog` conçus avant le deuxième provider ·
> les providers hors périmètre MVP.

> **Document historique** : il fixe les principes de conception, pas le contrat
> public. Pour le comportement réel des API et des webhooks, la référence est
> `docs/integration/` et `/docs/api` ; en cas de désaccord, ce sont eux qui font foi.

Ce document pose les **idées structurantes**, pas le code. Le code sera écrit au fil des lots ; ce qui ne doit pas bouger, ce sont les principes.

---

## 1. Philosophie — le Core est un intermédiaire

```
Apps métier (apps/*)
        │ parle au Core
        ▼
┌────────────────────────────────────────────────────────┐
│                       CORE                             │
│  Contrat canonique stable :                            │
│   • REST API versionnée                                │
│   • Webhooks sortants signés                           │
│   • Événements domaine publiés                         │
│                                                        │
│  Anti-Corruption Layer :                               │
│   • Les providers ne « parlent » qu'à l'infra Core     │
│   • Aucune string provider ne franchit le Core         │
│                                                        │
│  ┌──────────────────────────────────────────────────┐ │
│  │   Integration Framework (mini-framework interne) │ │
│  │   • ExternalApiCaller, templates, EndpointCatalog │ │
│  │   • AdapterLog, saga, modes, dev tools            │ │
│  └──────────────────────────────────────────────────┘ │
└────────────────────────────────────────────────────────┘
        │
        │ parle aux providers
        ▼
Providers externes (MTN, Orange, CinetPay, Firebase, …)
```

Trois invariants non-négociables :

1. **Une app métier ne sait jamais qu'un provider externe existe.** Elle envoie `POST /v1/payments` au Core, reçoit un JSON canonique, point.
2. **Un provider externe ne sait jamais qu'une app existe.** Il reçoit des requêtes signées par le Core, dans son format à lui, avec son vocabulaire à lui.
3. **Le Core possède toute la volatilité.** Changement de format, panne transitoire, schema drift, dépréciation : tout est absorbé dans l'adapter et la config. Le contrat publié reste immuable tant qu'on n'incrémente pas la version.

C'est exactement la discipline Anti-Corruption Layer de Vaughn Vernon : **deux contextes, deux vocabulaires, un traducteur entre les deux**.

---

## 1bis. Un mini-framework dédié — pas de la config, un framework

Les sections qui suivent ne décrivent pas une liste de bonnes pratiques disparates. Elles décrivent **un mini-framework interne au Core**, dédié à l'intégration des APIs externes. Le nommer et le délimiter est essentiel — c'est ce qui empêche qu'il dérive en une collection de hacks propres à chaque provider.

### Le framework en une phrase

> Un ensemble typé et introspectable qui prend une **intention métier** (« initier un paiement via CinetPay ») et la traduit en **appel HTTP conforme** au provider, en passant par les étapes d'auth, de signature, de retry, de logging, d'idempotence, de circuit breaker, de mode d'exécution et de persistance d'audit — le tout **sans que l'agent qui intègre un nouveau provider réinvente la roue**.

### Localisation dans le repo

```
packages/
└── integration-framework/          # ← le mini-framework
    ├── README.md                   # son manifeste, ses invariants, son upgrade guide
    ├── composer.json               # interne au monorepo (path repo)
    ├── src/
    │   ├── Configuration/          # ProviderConfig + sous-VO
    │   ├── Http/                   # ExternalApiCaller + ExternalRequest/Response
    │   ├── Template/               # RequestTemplate, ResponseTemplate, *Spec, builders
    │   ├── Endpoint/               # EndpointCatalog, ProviderTemplateDefaults, EndpointResolver
    │   ├── Mapping/                # interfaces RequestMapper/StatusMapper/WebhookMapper
    │   ├── Logging/                # AdapterLog entity + repository
    │   ├── Async/                  # ContinueOperation, CompensateOperation, DispatchEvent
    │   ├── Mode/                   # OperationMode, IdlePolicy, FeatureDisabledException
    │   ├── Security/               # AuthProvider, RequestSigner (avec slots d'extension)
    │   └── Console/                # core:adapter:* commands
    └── tests/
        ├── Architecture/           # tests d'inventaire, ACL, ACL providers
        └── Unit/                   # tests des composants mutualisés
```

**Note** : un seul package interne, pas un vendor externe. On ne le publie pas sur Packagist, on ne lui donne pas de versioning SemVer public. Il vit et meurt avec le monorepo. Ça évite la double dette (faire évoluer le framework ET à chaque release publier).

### Ce qui est **dedans** le framework

Tout ce qui est **mutualisable** entre providers :

- Les VO de configuration (`ProviderConfig` et ses sous-VO)
- L'`ExternalApiCaller` et son pipeline en 15 étapes
- Les `RequestTemplate` / `ResponseTemplate` et leurs builders
- Le `EndpointCatalog`, `ProviderTemplateDefaults`, `EndpointResolver`
- Les interfaces de mappers
- L'`AdapterLog` et son repository
- Le bus de saga (`ContinueOperation` / `CompensateOperation`)
- Les politiques de mode (`OperationMode`, `IdlePolicy`)
- Les `AuthProvider` et `RequestSigner` mutualisés
- Les commandes artisan `core:adapter:*`

### Ce qui est **dehors** du framework (spécifique par provider)

Tout ce qui est **volontairement non mutualisable** :

- Le mapping body (chaque provider a son format)
- Le mapping des statuts bruts (chaque provider a son vocabulaire)
- La logique métier de orchestration par provider (comment découper une opération en étapes de saga)
- Le `WebhookMapper` (chaque provider a son format de callback)
- Les Extras VO (subscription_key MTN, site_id CinetPay, etc.)

Ces pièces vivent dans `packages/payment/Infrastructure/HttpAdapter/MtnMomo/` (par exemple) et **consomment** le framework sans le connaître en détail.

### Pourquoi un mini-framework plutôt qu'une collection d'adapters

| Approche | Conséquence |
| --- | --- |
| **Collection d'adapters** — chaque provider est un dossier indépendant avec sa propre logique | Chaque intégration réinvente auth/retry/log/idempotency/breaker. Drift inevitable. Bug trouvé sur MTN est re-corrigé sur Orange, CinetPay, etc. |
| **Mini-framework + instances** — un framework mutualisé, chaque provider est une *instance* du framework | Auth/retry/log/idempotency/breaker sont centralisés. Un bug fixé sur MTN profite à tous. Ajouter un onzième provider = configurer une instance, pas coder un nouveau système. |

La seconde approche est l'**analogue interne** de ce que sont Spring Boot, NestJS, Phoenix, ou Django pour le web : un cadre applicatif que les *features* spécialisées viennent remplir, pas recopier.

### Frontières à défendre

Trois lignes rouges :

1. **Le framework ne dépend d'aucun provider.** Il ne contient aucun littéral `'mtn'`, `'cinetpay'`, `'SUCCESSFUL'`. Tout est générique. Test : `grep -ri 'mtn\|cinetpay\|orange' packages/integration-framework/src/` doit retourner zéro résultat.
2. **Le framework ne connaît aucun module métier.** `packages/integration-framework` ne dépend ni de `payment`, ni de `notification`, ni de `webhook`. Il est *au-dessus* dans la hiérarchie Deptrac (les modules métier en dépendent, lui n'en dépend aucun sauf `support`).
3. **Le framework ne contient aucune décision de produit.** Pas de politique de fees, pas de règle de commission, pas de logique de routage vers un provider plutôt qu'un autre. Ces décisions vivent dans les modules métier, qui *utilisent* le framework.

### Quand on hésite entre « dans le framework » ou « dans l'adapter »

La question à se poser : **est-ce qu'un futur provider aurait la même logique ?**

- Oui → dans le framework (ex : signature HMAC, retry exponentiel, persistance AdapterLog)
- Non → dans l'adapter (ex : chiffrement AES du payload spécifique à un PSP, découpage saga spécifique à MTN)

### README du framework

Le `packages/integration-framework/README.md` doit contenir :

- **Manifeste** — la phrase « un ensemble typé qui prend une intention métier et la traduit en appel HTTP conforme »
- **Invariants** — les trois lignes rouges ci-dessus
- **Catalogue de composants** — un index de tous les VO et interfaces, avec un exemple minimal pour chacun
- **Comment ajouter un nouveau provider** — un guide en 5 étapes
- **Upgrade guide** — comment migrer une version du framework (rare, mais nécessaire)

### Tests propres au framework

Le framework a ses propres tests, **indépendants** de tout provider :

- Tests unitaires de chaque composant mutualisé (`ExternalApiCaller`, builders, saga…)
- Tests d'architecture : ACL, mode, idempotence
- Tests d'inventaire : tous les composants exportés sont documentés, aucun n'est mort
- Tests de non-régression : un test snapshot du pipeline `ExternalApiCaller` pour détecter toute régression silencieuse

Les tests spécifiques à un provider (un test pour l'endpoint `mtn.refund` qui vérifie le format exact de la requête) vivent dans `packages/payment/tests/`, pas dans le framework.

---

---

## 2. Le contrat canonique pour les apps

Le Core expose **un seul** contrat vers l'extérieur, versionné (`v1`, `v2`…). Tout ce qui sort du Core parle ce contrat.

**REST API** — préfixe `/v{version}/...` (ce qui a été construit : `/v1`). Chaque ressource canonique est exposée **une seule fois**, le Core choisit le provider en interne. Le schéma OpenAPI (Scramble) **est** le contrat. Diff breakage = ADR + nouvelle version.

**Webhooks sortants** — le Core appelle les apps quand un événement les concerne. URLs déclarées via `POST /v1/webhook-endpoints`. Format signé HMAC-SHA256.

> **Corrigé le 2026-10-03 (audit, point 14)** : cette section proposait à l'origine des
> en-têtes `X-Core-*`, un `Idempotency-Key` et 5 tentatives. **Ce qui est livré** :
> enveloppe `{id, type, data}`, en-têtes `X-Signature` (HMAC de
> `X-Timestamp + "." + corps`), `X-Timestamp`, `X-Event-Id`, `X-Event-Type`,
> `X-Delivery-Id`, 8 tentatives avec backoff ×3 plafonné à 6 h, puis DLQ. La référence
> pour un intégrateur est [`docs/integration/webhooks.md`](../../integration/webhooks.md)
> ([ADR-0040](../../adr/0040-conception-lot-4-relais-outbox-webhooks-sortants.md),
> [ADR-0061](../../adr/0061-statuts-finals-et-contrat-api-minimal.md),
> [ADR-0063](../../adr/0063-documentation-integrateur-testable-et-test-de-webhook.md)).

**Événements domaine** — publiés sur l'outbox transactionnel en interne. Les apps peuvent s'abonner via webhooks (parution temps réel) ou via une file dédiée (Lot 5+).

**SDK léger optionnel** (Lot 5+) — sucre syntaxique typé au-dessus de la REST. Une app peut très bien consommer le Core en REST pur.

**Décision à trancher** : préfixe d'URL pour le versioning, parce que c'est le plus simple à router dans Laravel et le plus visible dans les logs. À formaliser dans un ADR.

---

## 3. Anti-Corruption Layer (ACL)

Trois traductions, **toutes** dans le Core :

1. **Inbound** (provider → Core) : `ResponseTemplate` + `StatusMapper` → objet domaine canonique
2. **Outbound** (Core → provider) : `RequestTemplate` + `BodySpec` → JSON provider
3. **Canonicalisation des statuts** : G6 — aucun littéral provider ne franchit l'adapter

**Tests d'architecture** à ajouter pour verrouiller l'ACL :

- `AppsNeverSpeakProviderTest` : aucune classe d'une `apps/*` ne doit importer une classe interne d'un adapter, ni connaître un `MtnStatus`/`CinetPayCode`/`FirebaseErrorCode`.
- `ContractsVocabularyTest` : seuls les types du Domain (enum canonique `PaymentStatus`, VO `Money`) apparaissent dans `Contracts/`.

---

## 4. Les quatre piliers du socle

### 4.1 Pilier 1 — Configuration provider déclarative

**Idée** : un provider est décrit par un fichier de config typé (`ProviderConfig`), pas par du code dispersé.

Une entrée par provider dans `config/providers.php` : code, environnement, base URL, authentification, timeouts, retries, signature, circuit breaker, mode async, politique webhook, politique idle, kill switch `enabled`, et un sac `extras` pour les spécificités provider.

Les sous-VO sont typés (pas des arrays) : `AuthStrategy`, `TimeoutPolicy`, `RetryPolicy`, `SignaturePolicy`, `CircuitBreakerPolicy`, `AsyncPolicy`, `WebhookPolicy`, `IdlePolicy`. Un loader lit `config/providers.php` et instancie tout au boot.

**Bénéfice** : ajouter un provider = une entrée de config + deux classes (`Adapter` + `Templates`). Pas de ligne de PHP métier, pas de manipulation d'env direct dans l'adapter.

### 4.2 Pilier 2 — Service d'appel HTTP central (`ExternalApiCaller`)

**Idée** : un seul composant sait parler HTTP, s'authentifier, signer, retenter, logger, breaker, redacter. Aucun adapter ne fait `Http::post()` directement.

Pipeline unique en 15 étapes : résolution config → check enabled/idle → check circuit breaker → auth → signature → idempotency key → headers standards → persister AdapterLog pending → envoi Guzzle → classification réponse → persister AdapterLog final → mapping → breaker → log → retour.

Collaborators injectés : `AuthProvider` (Bearer, Basic, ApiKey, mTLS), `RequestSigner` (HmacSha256, RsaSha256, AwsSigV4), `TokenCache` (Valkey), `CircuitBreaker`, `HttpTransport`, `AdapterLogRepository`, `PayloadRedactor`, `StructuredLogger`.

**Décision** : pas de chiffrement symétrique du payload dans le socle. C'est un cas rare ; un provider qui en a besoin implémente son propre `AuthProvider` tagué.

### 4.3 Pilar 3 — Templates de requête et de réponse

**Idée** : la forme de chaque appel HTTP est **décrite** dans une classe PHP (path, headers, body, signature, idempotency), pas codée en dur dans l'adapter. 70 % du travail d'un nouvel adapter devient déclaratif.

Un `RequestTemplate` contient :
- méthode HTTP + path avec placeholders (`/collection/v1_0/{referenceId}`)
- `headers` : déclarés via `HeaderSpec` avec une `HeaderSource` qui dit *où* trouver la valeur (`Static`, `ConfigKey`, `IdempotencyKey`, `Timestamp`, `Ulid`, `AuthDerived`, `CommandField`)
- `body` : déclaratif via `FieldSpec`, ou délégué à un `RequestMapper` PHP si trop complexe
- signature : référence vers une `SignaturePolicy`
- idempotency : `FromHeader` / `FromCommand` / `ProviderIssued` / `None`
- **mode** (cf. §6)

Un `ResponseTemplate` contient :
- `extractions` : chemin JSON dans la réponse brute → champ canonique
- `status` : où trouver le statut brut, statuts « still processing »
- mapper optionnel en dernier recours

Un `RequestTemplateBuilder` consomme un template + une commande + la config et produit un `ExternalRequest` final. Un `ResponseTemplateExtractor` consomme un `ResponseTemplate` + une réponse brute et produit le tableau de champs canoniques.

**Bénéfice** : changer la forme d'un appel = changer un template, pas recompiler un adapter. Un bug trouvé sur le builder profite à tous les providers.

### 4.4 Pilier 4 — Famille de Mappers

**Idée** : les mappers sont les seuls endroits où la traduction métier ↔ provider a lieu, avec des interfaces partagées.

Quatre interfaces : `RequestMapper`, `ResponseMapper`, `StatusMapper`, `WebhookMapper`.

Conventions strictes :
- Chaque mapper est `final readonly`, dans `Infrastructure/HttpAdapter/<Provider>/Mapping/`.
- Ne dépend que de VOs et enums du Domain.
- Les `StatusMapper` sont les **seuls** fichiers où les littéraux provider (`'SUCCESSFUL'`, `'PENDING'`, `'201'`) sont autorisés.

Une règle PHPStan custom `ForbiddenProviderStatusLiteralRule` (G6) rend la violation **impossible** à merger.

### 4.5 Le cas des APIs denses — quand un provider expose 20+ endpoints

Les sections précédentes montrent `initiate` et `fetchStatus`. C'est le **cas simple**. Le cas réel d'un provider comme MTN MoMo, CinetPay ou Orange Money, c'est :

- 20 à 50 endpoints par provider
- Certains avec des headers, body, ou politiques de signature **différentes**
- Des variantes v1/v2 cohabitant (migration progressive chez le provider)
- Des endpoints conditionnels (utilisés seulement dans certains flux)
- Une doc provider souvent lacunaire, changeante, versionnée par le provider lui-même

Le socle doit absorber cette complexité sans que l'agent réinvente la roue pour chaque endpoint.

#### Catalogue d'endpoints

**Idée** : un provider expose un **catalogue typé** de tous ses endpoints, pas une collection désordonnée de templates dispersés.

Un `EndpointCatalog` regroupe tous les `RequestTemplate` et `ResponseTemplate` d'un provider, indexés par un nom logique (`'mtn.initiate'`, `'mtn.status'`, `'mtn.refund'`, etc.). C'est ce catalogue qui est exposé à l'`ExternalApiCaller` et qui peut être introspecté.

#### Héritage et configuration partagée

**Idée** : la plupart des endpoints d'un même provider partagent des invariants — base URL, headers communs (X-Target-Environment, Ocp-Apim-Subscription-Key), schéma d'auth, format de réponse. Les redéclarer 30 fois est une source de bugs.

Un `ProviderTemplateDefaults` regroupe ces invariants. Chaque `RequestTemplate` peut les hériter implicitement et ne déclare que ses spécificités.

Exemple conceptuel pour MTN MoMo :

```
defaults:
  base_headers:
    X-Target-Environment:        from config.target_environment
    Ocp-Apim-Subscription-Key:   from config.subscription_key
  auth:                          Bearer (token cached)
  signature:                     none
  error_mapping:                  'INVALID_REQUEST' → ProviderPermanentError
                                  'RESOURCE_NOT_FOUND' → ProviderPermanentError
                                  anything 5xx       → ProviderTransientError

endpoints:
  initiate:           POST /collection/v1_0/requesttopay
                      body: MtnInitiateBodyMapper
                      response: 202 + webhook
                      mode: async

  fetch_status:       GET  /collection/v1_0/requesttopay/{referenceId}
                      response: MtnStatusResponse
                      mode: sync

  refund:             POST /collection/v1_0/refund
                      body: MtnRefundBodyMapper
                      mode: async (saga)

  get_account_balance: GET /collection/v1_0/account/balance
                      mode: sync

  validate_account:   GET /collection/v1_0/account/holdermsisdn/{msisdn}/{type}
                      mode: sync

  # ... 20 autres endpoints
```

L'`adapter` ne déclare plus chaque endpoint à la main ; il consomme le catalogue, et l'orchestrateur résout l'endpoint par son nom logique.

#### Spécialisation par endpoint

Quand un endpoint déroge aux defaults (corps différent, signature custom, retry différent, mode particulier), il déclare ses spécificités — le reste est hérité.

Exemples typiques de spécialisation :
- **BodyMapper custom** : `initiate` utilise `MtnInitiateBodyMapper` (corps riche), `validate_account` n'a pas de body
- **Signature custom** : un endpoint particulier exige une signature RSA au lieu de HMAC
- **Mode différent** : `initiate` est async (saga), `fetch_status` est sync
- **Idempotency différente** : `initiate` utilise l'`Idempotency-Key` header, `refund` utilise un champ du body
- **RetryPolicy plus agressive** : `fetch_status` retry 5 fois (cheap), `initiate` retry 1 fois (déjà async)
- **Timeout différent** : `initiate` 30 s, `fetch_status` 5 s

#### Versioning des endpoints

Quand le provider publie une v2 de son API, l'ancien et le nouveau cohabitent souvent. Le catalogue doit le refléter.

Deux approches :
1. **Deux endpoints séparés** : `initiate_v1`, `initiate_v2`. L'adapter choisit selon une config ou un `Accept` header.
2. **Un endpoint avec version paramétrable** : `initiate` lit la version depuis la config du provider.

**Recommandation** : l'approche 1, parce qu'elle permet la migration progressive sans casser les adapters qui dépendent encore de v1.

#### Endpoints conditionnels

Certains endpoints ne sont utilisés que dans des flux spécifiques. Plutôt que de les charger tous en mémoire, le catalogue peut marquer un endpoint comme `enabled: bool` selon une condition runtime :

- activé seulement si `provider.extras.product === 'mtn-cameroon'`
- activé seulement si une feature flag est activée
- activé seulement en sandbox

#### Documentation provider comme entrée de premier rang

**Idée** : la doc officielle du provider (MTN MoMo API docs, CinetPay developer portal, etc.) doit être **référencée depuis l'adapter** et **mise à jour automatiquement** quand elle change.

Chaque endpoint du catalogue référence son URI canonique dans la doc provider. Un job programmé (Lot 5+) vérifie périodiquement que les endpoints documentés n'ont pas changé (URL, schéma, header obligatoire) et alerte si drift.

Exemple conceptuel :

```
endpoint 'mtn.initiate':
  doc_url: 'https://momodeveloper.mtn.com/api-documentation/api-description/'
  last_verified_at: 2026-09-14
  schema_version: 'v1.0'
  # Si le provider change l'URL ou un champ obligatoire,
  # le job signale l'incohérence
```

#### Endpoint discovery — introspection runtime

**Idée** : l'opérateur (ou un test) doit pouvoir lister tous les endpoints supportés par un provider sans lire le code.

```
php artisan core:adapter:endpoints mtn-momo

# Affiche :
# mtn-momo — 23 endpoints
#
#   initiate            POST /collection/v1_0/requesttopay            [async]
#   fetch_status        GET  /collection/v1_0/requesttopay/{id}      [sync]
#   refund              POST /collection/v1_0/refund                  [async]
#   validate_account    GET  /collection/v1_0/account/holdermsisdn/.. [sync]
#   ...
#
# 3 endpoints désactivés (feature flag)
# 2 endpoints marqués deprecated
```

Combiné avec `core:adapter:check`, l'opérateur sait d'un coup d'œil :
- quels endpoints existent
- lesquels sont async / sync / idle
- lesquels sont désactivés ou deprecated
- lesquels ont une doc provider stale

#### Tests sur un grand nombre d'endpoints

Avec 20+ endpoints, le risque c'est la **dette de test**. La stratégie :

- **Un test Contract par endpoint** obligatoire — générable via `core:adapter:make` qui scaffolde le test en même temps que le template
- **Tests d'inventaire** : `EndpointInventoryTest` vérifie que tous les endpoints listés dans le catalogue ont un test, et que tous les tests référencent un endpoint connu du catalogue (pas de test orphelin, pas d'endpoint orphelin)
- **Tests de non-régression de contrat** : un test qui vérifie que la **forme** de l'appel n'a pas changé (URL, headers, body shape) sans relancer l'integration — utile dans la CI rapide

#### Ce que ça change concrètement

```
Avant (1 provider = 1 fichier Templates) :  CinetPayTemplates.php
                                            ├── initiate()
                                            ├── fetchStatus()
                                            └── refund()

Maintenant (1 provider = 1 catalogue) :
  CinetPayCatalog.php
    defaults: { base_headers, auth, error_mapping }
    endpoints:
      ├── initiate    → RequestTemplate + ResponseTemplate
      ├── fetchStatus → RequestTemplate + ResponseTemplate
      ├── refund      → RequestTemplate + ResponseTemplate
      └── ...

  Plus facile à auditer.
  Plus facile à tester endpoint par endpoint.
  Plus facile à désactiver / versionner / migrer.
```

**L'adapter** ne déclare plus la liste de ses endpoints ; il consomme le catalogue.

---

## 5. ProviderRegistry — résolution dynamique

**Idée** : le code applicatif demande « donne-moi l'adapter pour le provider X », pas `match ($provider) { ... }`.

Le `ProviderRegistry` indexe les `ProviderGateway` tagués Laravel par leur `ProviderCode`. Les use cases appellent `registry->gatewayFor($code)`.

**Règle d'or** : aucun `match`/`switch` sur un `ProviderCode` dans le code applicatif. Ajouter un provider = un tag dans un ServiceProvider, point.

---

## 6. Modes d'exécution — la décision structurante

Le mode d'exécution d'une opération est **toujours configurable**, jamais codé en dur dans l'adapter. Il est déclaré sur chaque `RequestTemplate` (champ `mode`) et lu par l'orchestrateur. Le même provider peut mixer sync et async selon l'opération.

### 6.1 Les quatre modes

| Mode | Sémantique | Use case |
| --- | --- | --- |
| `sync` | L'appel HTTP est bloquant, le caller attend la réponse finale | Statut provider, vérifications rapides |
| `async` (saga) | L'appel HTTP accepte, on retourne immédiatement, la suite est gérée par la saga | Initiation paiement, opérations avec webhook |
| `idle` | L'adapter ne contacte pas le provider, retourne un succès factice (`simulated: true`) | Tests, maintenance planifiée, migration |
| `disabled` | L'adapter rejette l'appel (`feature_disabled`) | Kill switch d'urgence |

### 6.2 Sync vs async — deux questions indépendantes

Le choix répond à **deux questions distinctes** :

**Question A — la nature du provider** : renvoie-t-il le statut final dans sa réponse HTTP (`200 OK` avec payload complet) ou accepte-t-il puis notifie plus tard (`202 Accepted` + webhook) ?

**Question B — l'attente du caller Core** : le use case veut-il bloquer jusqu'au statut final, ou retourner immédiatement et réagir à l'événement ?

Les deux sont **indépendantes** :

```
Provider        │ Sync côté Core          │ Async côté Core (saga)
─────────────────┼────────────────────────┼──────────────────────────
Sync natif       │ OK (1 appel)           │ OK (réponse immédiate +
                 │                        │   caller n'attend pas)
Async natif      │ Possible (poll status) │ OK (le plus naturel)
(202 + webhook)  │                        │
```

**Conséquence opérationnelle** : un provider sync natif peut très bien être exposé en async côté Core (et inversement). C'est le `RequestTemplate::mode` qui décide, pas la nature du provider.

### 6.3 Idle et disabled — bascules d'exploitation

L'`IdlePolicy` et un flag `enabled` permettent de basculer à chaud sans redéployer :

- `enabled = false` : kill switch → `503 feature_disabled`, AdapterLog status `disabled`
- `idle = true` (via variable d'env) : retour succès factice, pas d'appel HTTP, AdapterLog status `idle`

**Cas d'usage** :
- Migration progressive : MTN en `idle=true` pendant qu'Orange prend le relais. Aucun downtime, rollback en une variable d'env.
- Maintenance planifiée : provider en panne dimanche soir → `idle=true`, les callers continuent de recevoir une réponse propre.
- Test d'intégration : en local, `idle=true` permet de tester toute la chaîne applicative sans réseau.
- Rollback d'urgence : `enabled=false` coupe l'intégration sans redéployer.

L'`enabled` et l'`idle` sont lus par l'`ExternalApiCaller` aux **étapes 1 et 2** de sa pipeline — avant même le circuit breaker.

### 6.4 Saga — quand `mode = async`

Une opération marquée `async` est pilotée par une saga. Deux messages suffisent à exprimer toute la coordination :

- **`ContinueOperation`** — étape suivante à exécuter
- **`CompensateOperation`** — compensation à exécuter en cas d'échec définitif

Un `HandleContinueOperation` consomme `ContinueOperation`, exécute la partie suivante via l'adapter, et :
- si fini → émet l'événement final
- sinon → dispatch le `ContinueOperation` suivant
- si erreur transitoire → retry
- si erreur permanente → dispatch `CompensateOperation`

Un `HandleCompensateOperation` exécute la compensation (rollback financier, rollback commission, etc.) et marque la transaction en FAILED.

Le dispatch de message est lui-même **robuste** : retry avec backoff exponentiel (200 ms → 3.2 s, 5 tentatives) avant de basculer dans une file de reprise manuelle. On ne perd jamais un message.

**Quand marquer `async`** : quand la nature du provider l'impose (réponse `202` + webhook), ou quand le use case ne veut pas bloquer. Tout le reste est sync.

---

## 7. AdapterLog — la mémoire persistante du Core

**Idée** : chaque appel provider est persisté en base comme un record d'audit. C'est la mémoire du Core.

Schéma de la table `support.adapter_logs` : `operation_id`, `service` (quelle opération), `provider_code`, `request_headers`, `request_body` (envoyé), `request_body_raw` (brut si chiffré), `response_status`, `response_headers`, `response_body`, `response_body_raw`, `response_time_ms`, `error_code/message`, `status` (`pending` → `success`/`failed`/`idle`/`disabled`), `trace_id`, `created_at`, `completed_at`.

L'`ExternalApiCaller` crée un `AdapterLog` en `pending` à l'étape 8 de sa pipeline, le met à jour en final à l'étape 11.

**Pourquoi c'est critique** :
- **Debug** : un client appelle le support « mon paiement a échoué » → recherche par `operation_id`, on voit la requête exacte envoyée, la réponse exacte reçue, le temps de latence.
- **Replay** : rejouer la requête stockée contre un mock sans toucher au code applicatif.
- **Audit** : qui a déclenché quoi, quand, avec quel résultat.
- **Alerte** : détecter un fournisseur qui se dégrade en SQL (taux de `failed` qui monte, latence qui dérive).
- **Conformité** : conservation de la trace des transactions monétaires.

**Exposé aux apps** : `GET /api/v1/payments/{id}/logs` (Lot 4+). Le `PayloadRedactor` nettoie les secrets avant persistence si `save_raw_payload = false`.

---

## 8. Webhooks entrants — vérification et dédup

Le Core reçoit des webhooks des providers (paiement confirmé, livraison SMS, etc.). Chaque webhook suit le même pipeline :

1. **Log brut** de la requête (même invalide) pour debug
2. **Vérification signature** HMAC avec le `verifySecret` configuré ; vérification du clock skew
3. **Mapping** → événement canonique (`PaymentStatusChanged`, `NotificationDelivered`, etc.)
4. **Idempotence** par le champ déclaré dans `WebhookPolicy.idempotencyFieldPath` (souvent `transactionId`)
5. **Traitement métier** via le use case (asynchrone via `ContinueOperation`)
6. **Mise à jour de l'`AdapterLog`**

Si la signature est invalide → `401`. Si le payload est mal formé → `400`. Si déjà traité → `200 {status: already_processed}`. Sinon → `200 {status: accepted}`.

**Le use case de traitement** émet ensuite un événement domaine qui sera publié sur l'outbox et dispatché vers les apps abonnées via webhooks sortants (§2).

---

## 9. Skeleton d'un adapter

Pour un provider complet :

```
packages/payment/src/Infrastructure/HttpAdapter/MtnMomo/
├── MtnMomoAdapter.php              # implements ProviderGateway
├── Templates/
│   └── MtnTemplates.php            # RequestTemplate + ResponseTemplate
├── Mapping/
│   ├── MtnInitiatePaymentMapper.php   # RequestMapper si besoin
│   ├── MtnStatusMapper.php            # StatusMapper (G6)
│   └── MtnConfig.php                  # Extras VO
├── Exception/
│   ├── UnknownMtnStatus.php
│   └── MtnSignatureVerificationFailed.php
└── Tests/
    ├── Unit/Templates/MtnTemplatesTest.php
    ├── Unit/Mapping/MtnStatusMapperTest.php
    ├── Contract/MtnMomoAdapterTest.php
    └── Integration/MtnMomoSandboxTest.php   # @group sandbox
```

Pour un provider simple (corps plat, sans transformation exotique), on peut n'avoir que `Templates/` + `StatusMapper.php` — c'est le cas typique (CinetPay).

**L'adapter ne fait jamais** :
- d'appel HTTP direct (→ `ExternalApiCaller`)
- d'assemblage d'URL (→ `RequestTemplate::pathTemplate`)
- de construction de headers (→ `RequestTemplate::headers`)
- de sérialisation JSON (→ `RequestTemplate::body` ou `RequestMapper`)
- de retry manuel (→ `RetryPolicy`)
- de signature inline (→ `RequestSigner`)
- de chiffrement de payload (→ `AuthProvider` custom si besoin)

L'adapter **décrit** l'intention (« je veux faire `cinetpay.initiate` pour cet intent »), le builder assemble, l'`ExternalApiCaller` envoie, l'extracteur parse, le `StatusMapper` traduit.

---

## 10. Stratégie de test

Quatre niveaux par provider, hiérarchisés par vitesse et par ce qu'ils prouvent :

| Niveau | Vitesse | Quoi | Garantie |
| --- | --- | --- | --- |
| **Unit (templates)** | < 50 ms | `RequestTemplate` produit le bon `ExternalRequest` | Forme de l'appel correcte |
| **Unit (mappers)** | < 50 ms | `StatusMapper`, `RequestMapper`, `WebhookMapper` | Traduction fidèle |
| **Unit (saga)** | < 100 ms | `HandleContinueOperation`, `HandleCompensateOperation` avec mocks | Coordination correcte |
| **Contract** | < 500 ms | Adapter avec `Http::fake()`, vérif headers/signature/body | Conformité au protocole provider |
| **Integration** | secondes | Vraie sandbox provider (`@group sandbox`) | Comportement réel du provider |

Tests transverses (uniques, exécutés en CI à chaque PR) :
- `AppsNeverSpeakProviderTest` (ACL)
- `CanonicalStatusTest` (G6b)
- `IdempotencyTest` (G7)
- `MoneyColumnsTest` (G5)
- Tests de saga : retry sur `ProviderTransientError`, rollback sur erreur permanente, dispatch du message suivant quand pas fini.

---

## 11. Anti-patterns à proscrire

| Anti-pattern | Alternative |
| --- | --- |
| App métier qui appelle directement MTN/CinetPay/Firebase | Passer par le Core (ACL) |
| Adapter qui construit son propre `GuzzleClient` | `ExternalApiCaller` injecté |
| Adapter qui retry « à la main » avec `usleep` | `RetryPolicy` du `ProviderConfig` |
| Adapter qui assemble l'URL avec concaténation | `pathTemplate` dans `RequestTemplate` |
| Adapter qui sérialise le JSON avec `json_encode` à la main | `BodySpec` dans `RequestTemplate` |
| `match ($providerCode) { ... }` dans un use case | `ProviderRegistry::gatewayFor($code)` |
| Token OAuth stocké dans une variable statique mutable | `TokenCache` (Valkey) injecté |
| Status provider string remonté dans une exception | Mapper dans l'adapter, toujours |
| Webhook traité sans vérif signature | `WebhookMapper::verifySignature` en première ligne |
| Log qui contient `Authorization: Bearer …` | `PayloadRedactor` automatique |
| Opération saga faite en synchrone avec timeout 60 s | Message + handler |
| Chiffrement symétrique généralisé en pilier | `AuthProvider` custom si vraiment nécessaire |
| Mode sync/async codé en dur dans l'adapter | `RequestTemplate::mode` |
| Kill switch par redéploiement | `enabled` env var, bascule à chaud |
| Migration provider via downtime | `idle=true` sur l'ancien, le nouveau prend le relais |
| Pas d'`AdapterLog` persisté | Bug, ne pas merger |
| Pas de ré-essai sur dispatch de message | `DispatchEvent` avec backoff |
| Pas de test ACL | `AppsNeverSpeakProviderTest` |
| 30 templates dispersés sans catalogue | `EndpointCatalog` indexé (§4.5) |
| Headers/auth redéclarés sur chaque endpoint | `ProviderTemplateDefaults` hérités (§4.5) |
| Endpoint orphelin (pas de test) ou test orphelin (pas d'endpoint) | `EndpointInventoryTest` (§4.5) |
| Doc provider driftée jamais vérifiée | Référence `doc_url` + job de vérification (§4.5) |
| Considérer le socle d'intégration comme une simple « config » au lieu d'un mini-framework | Le nommer, le délimiter, lui donner un README et des tests propres (§1bis) |
| Framework qui dépend d'un provider (un `MtnStatus` qui traîne dans `packages/integration-framework/`) | Les trois lignes rouges (§1bis) : grep zéro résultat |
| Adapter qui réimplémente auth/retry/log/idempotency | Consommer le framework, c'est son job |
| Framework qui contient une décision de produit (commission, routage provider) | Décision = dans le module métier, framework = outillage générique |
| Oublier qu'on change de provider un jour | Tout via `ProviderConfig` + templates |

---

## 12. Dev tools — accélérer l'arrivée d'un nouveau provider

Trois commandes artisan pour scaffolder et vérifier la complétude d'une intégration :

```
php artisan core:adapter:make MtnMomo \
    --domain=payment \
    --base-url-env=MTN_BASE_URL \
    --auth=bearer \
    --status-canonical=PaymentStatus \
    --with-webhook
```

Génère le squelette complet (§9) avec TODOs aux endroits à compléter.

```
php artisan core:adapter:list
```

Affiche tous les providers configurés + leur état (`configured` / `templates_ready` / `wired` / `tested`).

```
php artisan core:adapter:check mtn-momo
```

Vérifie que :
- `config/providers.php` contient l'entrée
- l'adapter implémente `ProviderGateway`
- les `RequestTemplate` couvrent toutes les opérations listées dans la doc provider
- les tests Contract et Integration existent (au moins en stub)
- les statuts bruts sont confinés dans le `StatusMapper`
- l'`AdapterLog` est persisté sur chaque appel
- **chaque endpoint du catalogue a un test ; chaque test référence un endpoint connu** (`EndpointInventoryTest`)
- **les références `doc_url` sont valides** (Lot 5+)

```
php artisan core:adapter:endpoints mtn-momo
```

Liste tous les endpoints d'un provider avec leur mode (sync/async), leur statut (enabled/disabled/deprecated), et leurs références doc. Indispensable pour auditer un provider à 20+ endpoints.

---

## 13. Périmètre de livraison

### Lot 0 — Socle d'intégration (avant Lot 1)

**Livrable principal** : le package `packages/integration-framework/` avec son README, son catalogue de composants, ses tests propres. C'est le mini-framework — il existe *avant* tout adapter concret.

**Pilier 1 — Config** : `ProviderConfig` + sous-VO (`AuthStrategy`, `TimeoutPolicy`, `RetryPolicy`, `SignaturePolicy`, `CircuitBreakerPolicy`, `AsyncPolicy`, `IdlePolicy`, `WebhookPolicy`). `ProviderConfigLoader`. `config/providers.php` structuré (vide).

**Pilier 2 — ExternalApiCaller** + 5 `AuthProvider` (Bearer, Basic, ApiKey, mTLS, et un slot pour custom). `HmacSha256Signer` + `RsaSha256Signer` + `AwsSigV4Signer`. `ValkeyCircuitBreaker`, `TokenCache`, `PayloadRedactor`, `HttpTransport`.

**Pilier 3 — Templates** : `RequestTemplate` + `ResponseTemplate` + `*Spec`. `RequestTemplateBuilder` + `ResponseTemplateExtractor`. `OperationMode` enum (sync/async).

**Pilier 4 — Mappers** : 4 interfaces + `MapperChain` éventuelle.

**Pilier 5 — Endpoint Catalog** : `EndpointCatalog`, `ProviderTemplateDefaults`, `EndpointResolver`. La structure qui absorbe la complexité des APIs denses (§4.5).

**Orchestration** :
- Schema `support.adapter_logs` + migration
- `DispatchEvent` trait (retry du dispatch)
- `ContinueOperation` + `CompensateOperation` + handlers squelette
- `FeatureDisabledException` + mapping HTTP 503
- `IdleResponse` builder (succès factice)

**ProviderRegistry** + tag d'adapters.

**Dev tools** : `core:adapter:make`, `core:adapter:list`, `core:adapter:check`, `core:adapter:endpoints`.

**Tests** : `AppsNeverSpeakProviderTest`, `AdapterLogTest`, `SagaTest`, `EndpointInventoryTest`.

### Lot 1 — Identity (ne consomme pas le socle)

### Lot 3 — MTN MoMo (premier provider)

- `MtnMomoAdapter` + `MtnTemplates` + mappers
- `MtnMomoWebhookController`
- Tests Unit / Contract / Integration
- `ForbiddenProviderStatusLiteralRule` activée (G6)
- `IdempotencyTest` non-skip (G7)

### Lot 4 — CinetPay (valide la réutilisabilité du socle)

Cible : ≤ 2 jours-homme.

### Lot 5 — Premier adapter asynchrone (Firebase Notifications)

- Saga `SendNotification` → `ContinueOperation` → handler de step
- Webhook entrant pour delivered/read
- Événements domaine publiés sur l'outbox

### Lot 6+ — Orange Money, DGI, Twilio, SendGrid, S3…

Chaque provider suit le même squelette. Coût marginal cible : **1-3 jours**.

---

## 14. Conclusion — ce qui rend cette architecture « soft »

**Pour les apps consommatrices** :
- Le contrat publié est immuable tant qu'on ne bump pas la version.
- Aucune ne recompile quand un provider change.
- Aucune ne connaît MTN/CinetPay/Firebase par leur nom.

**Pour l'équipe qui maintient** :
- Un nouveau provider = `config/providers.php` + `Adapter` + `Templates` + `StatusMapper`. Pas de Guzzle, pas de crypto, pas de retry à réinventer.
- Les `AdapterLog` rendent le debug trivial : un incident = une recherche SQL par `operation_id`.
- La saga absorbe les opérations longues sans bloquer les workers HTTP, avec rollback automatique.
- Les modes `sync`/`async`/`idle`/`disabled` sont configurés, pas codés.
- L'ACL protège la vocabulary integrity, testée par `AppsNeverSpeakProviderTest`.

**Pour l'exploitation** :
- Bascule MTN → Orange = `idle=true` sur MTN + activation Orange. Pas de downtime, pas de redéploiement.
- Kill switch = `enabled=false`. Migration d'urgence = `idle=true`.
- L'ajout d'un onzième provider est trivial parce que les sept piliers sont en place.

C'est la discipline qui transforme un projet « ça marche » en un projet « ça reste maintenable quand l'équipe double, que le dixième provider débarque, et qu'on doit changer MTN pour Orange un dimanche soir en prod ».

---

*À valider avec le Tech Lead avant le Lot 0.*