# core/support — shared kernel

Tous les modules peuvent dépendre de ce package ; lui ne dépend d'aucun module.
C'est exactement le profil du package qui finit en fourre-tout. Ce README est
donc un **manifeste fermé** : ce qui n'y est pas listé n'a rien à faire ici.

Décision de référence : [ADR-0020](../../docs/adr/0020-shared-kernel-support.md).

## Ce qui a le droit de vivre ici

| Primitive | Emplacement | Statut |
| --------- | ----------- | ------ |
| `Clock` (port) et `SystemClock` (UTC) | `src/Clock.php`, `src/SystemClock.php` | Livré |
| `Environment` (sandbox / live) | `src/Environment.php` | Livré |
| `Identifier` (port) et `Uuid7Identifier` | `src/Identifier.php`, `src/Uuid7Identifier.php` | Livré — Lot 1 |
| Réponses d'erreur RFC 7807 | `src/Http/ProblemDetails.php` | Livré |
| Middleware `CorrelationId` | `src/Http/Middleware/CorrelationId.php` | Livré |
| `Money` — enveloppe de brick/money | `src/Money/` | Lot 2 — [ADR-0021](../../docs/adr/0021-wrapper-money.md) |
| Middleware d'idempotence + port `IdempotencyStore` | `src/Idempotency/` | Lot 2 — [ADR-0025](../../docs/adr/0025-design-idempotence.md) |
| Socle d'intégration externe (HTTP sortant) | `src/Integration/` | **Livré** — [ADR-0026](../../docs/adr/0026-integration-providers-externes.md) + [ADR-0031](../../docs/adr/0031-socle-integration-en-parallele-du-lot-2.md) + [ADR-0034](../../docs/adr/0034-socle-integration-scope-structure.md) |

### Composition du socle d'intégration

- **Configuration** : `ProviderConfig`, `AuthStrategy`, `TimeoutPolicy`, `RetryPolicy`, `SignaturePolicy`, `CircuitBreakerPolicy`
- **Auth** : `AuthProvider` (port) + `ApiKeyAuthProvider` (header ou body), `BasicAuthProvider`, `BearerAuthProvider` (statique)
- **Signing** : `RequestSigner` (port) + `HmacSha256RequestSigner`, `WebhookSignatureVerifier` (port) + `HmacSha256WebhookVerifier`
- **Mapping** : `StatusMapper<T>` (port — verrouille G6)
- **CircuitBreaker** : `CircuitBreaker` (port) + `InMemoryCircuitBreaker`
- **Transport** : `HttpTransport` (port — G6c) + `LaravelHttpTransport`
- **Redaction** : `PayloadRedactor` + `SensitiveKeySet` (headers + JSON keys)
- **Caller** : `ExternalApiCaller` (port) + `ExternalApiCallerImpl` (pipeline complet)
- **Provider contract** : `ProviderGateway` (port), `ProviderCode` (VO), `ProviderRegistry` (port)
- **Credentials** : `Credentials` (statique défaut + override per-call — multi-tenant)
- **Correlation** : `CorrelationIdProvider` (port) + `LaravelContextCorrelationIdProvider`
- **Token cache** : `TokenCache` (port seul — l'impl Valkey viendra de l'app shell)
- **Exceptions** : `ProviderException` + `ProviderTransientError`, `ProviderPermanentError`, `ProviderAuthError`, `ProviderDisabledException`, `UnknownProviderStatusException`, `TokenCacheUnavailableException`

### Pattern d'un adapter (template)

Un adapter concret consomme le socle sans jamais en connaître l'implémentation. La structure canonique est dans `packages/<module>/src/Infrastructure/HttpAdapter/<Provider>/` :

```
<Provider>/
├── <Provider>Adapter.php       # implements <Module>Gateway extends ProviderGateway
├── Templates/                   # (Lot 3) templates déclaratifs — pas requis aujourd'hui
├── Mapping/
│   └── <Provider>StatusMapper.php  # implements StatusMapper<ModuleStatus>
├── Exception/
│   └── Unknown<Provider>Status.php  # extends UnknownProviderStatusException
└── Tests/
    ├── Unit/Mapping/...
    └── Contract/<Provider>AdapterTest.php  # Http::fake()
```

L'adaptateur :
- Reçoit `ExternalApiCaller`, `ProviderConfig` par injection
- Construit un `ExternalRequest` par appel (méthode, path, body, idempotencyKey)
- Appelle `$this->caller->send($this->config, $request)`
- Traduit la réponse via `$this->statusMapper->map($response->body['status'])`

Aucun appel HTTP direct, aucun statuts provider string exposé en dehors de l'adapter.

### Authentification par opération

Le champ `ExternalRequest::requiresAuth` (défaut `true`) permet de désactiver l'auth sur une opération spécifique. Cas d'usage : un provider dont seules certaines opérations (ex. `payout`, `balance`) exigent une clé privée ; les autres (ex. `payin`, `checkStatus`) sont appelées avec la clé publique seule, présente dans l'URL. L'adaptateur construit alors la requête avec `requiresAuth: false` et attache la clé privée manuellement via le champ `headers`. Les headers `Correlation-Id` et `Idempotency-Key` sont toujours ajoutés — seul le bloc auth est sauté.

## Ce qui est interdit ici

- **Toute persistance** : Eloquent, query builder, `DB`, `Schema`, `Redis`
  (garde-fou G3d). Une primitive qui a besoin de stocker déclare un port ; le
  shell applicatif l'implémente.
- **Toute dépendance vers un module** (G1).
- **Toute règle métier** et toute classe nommée d'après une capacité (paiement,
  wallet, frais, notification…).
- **Tout vocabulaire provider** (MTN, Orange, WhatsApp…).

## Ajouter une primitive

Une primitive n'entre que si elle est utilisée par **au moins deux modules**,
sans règle métier ni connaissance d'un provider. La PR qui l'ajoute met à jour
ce tableau **et** l'ADR-0020 ; elle passe par le comité d'architecture.
