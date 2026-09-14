# ADR-0034 — Socle d'intégration : scope, structure et compromis retenus

- **Statut** : Accepté — implémentation livrée
- **Date** : 2026-09-15
- **Dérives concernées** : D3 (vocabulaire provider dans les couches hautes), D4 (réinvention par provider)

## Contexte

[ADR-0026](0026-integration-providers-externes.md) a établi les principes
d'intégration des providers externes (anti-corruption layer, G6c,
registre de providers, kill switch, pipeline des callbacks). [ADR-0031](0031-socle-integration-en-parallele-du-lot-2.md)
a avancé la construction du socle générique en parallèle du Lot 2.

Une fois le code écrit, plusieurs décisions de scope ont été tranchées
pendant l'implémentation et méritent d'être tracées ici — soit parce
qu'elles restreignent ce qu'un futur adapter peut faire, soit parce
qu'elles reportent un choix au Lot 3.

## Décision

### 1. AuthStrategy et Credentials sont séparés

`AuthStrategy` porte la **forme** de l'authentification (kind, structural
fields). `Credentials` porte les **valeurs** (apiKey, bearerToken,
clientId/secret, username/password). Cette séparation permet deux patterns
qui coexistent dans le même socle :

- **Static credentials** chargées une fois au boot depuis
  `config/providers.php` (`ProviderConfig::defaultCredentials`).
- **Per-call credentials** injectées par le caller via
  `ExternalRequest::credentials`, qui **override** les valeurs par
  défaut champ par champ (`Credentials::mergeWith()` est asymétrique :
  un null dans l'override ne wipe pas la valeur par défaut).

Cela rend le socle immédiatement compatible avec le multi-tenant : un
même provider (MTN, CinetPay) peut être appelé avec des clés
différentes selon le caller sans dupliquer l'adapter.

### 2. ApiKey supporte header **et** body

Le pattern `api_key_placement: header | body` et le champ
`api_key_body_field` répondent au constat que certains providers (form-encoded,
certains REST) exigent la clé dans le corps. L'`ApiKeyAuthProvider`
retourne un `AuthApplyResult` qui peut muter le **body** (placement
body) ou les **headers** (placement header). L'`AuthProvider::apply`
prend l'ancien `apply()` qui retourne `AuthApplyResult{headers, body}`
— le body est `null` quand l'auth provider n'a rien à modifier.

### 3. BearerAuthProvider est statique, pas OAuth2

Le socle fournit un `BearerAuthProvider` **statique** (la clé est le
token, point). Il ne fait aucun appel HTTP pour récupérer un token.

Le pattern OAuth2 token-endpoint (MTN-style) existe et fonctionne via
l'adaptateur : l'adapter construit lui-même la requête vers le token
endpoint en utilisant `HttpTransport` + `BasicAuthProvider` + `TokenCache`
puis applique le token reçu via `BearerAuthProvider`. Le socle n'abstrait
pas cette danse parce qu'elle est rare dans le périmètre MVP et qu'elle
repose sur le socle sans en faire partie.

### 4. TokenCache est un port seul

Le port `TokenCache` est déclaré, avec `NullTokenCache` comme sentinel
par défaut (qui throw sur `put()`). Aucun implémentation réelle n'est
livrée parce que :

- Une implémentation in-memory par défaut masquerait en production la
  multiplication des refresh tokens entre workers.
- L'implémentation réelle (Valkey) appartient à l'application shell, pas
  au shared kernel (G3d).

Les adapters qui ont besoin d'OAuth2 fourniront leur propre binding
de `TokenCache`.

### 5. ProviderConfig expose un kill switch ET un flag debug

- `ProviderConfig::enabled` (bool, défaut true) — kill switch sans
  redéploiement. Lecture recommandée depuis une variable d'env dans
  `config/providers.php`. `false` → `ProviderDisabledException`, aucun
  appel HTTP.
- `ProviderConfig::debug` (bool, défaut false) — quand `true`,
  l'`ExternalApiCaller` logge un événement `integration.debug.*` avec les
  headers et bodies **redactés** à chaque tentative. Utile pour intégrer
  un nouveau provider ; **désactivé en production** parce que même
  redactés les bodies peuvent contenir de la PII.

### 6. Les statuts HTTP sont classifiés en 3 buckets

- `ProviderAuthError` pour 401, 403 (auth à régler, pas à retry).
- `ProviderTransientError` pour 408, 425, 429, 500, 502, 503, 504 ET
  pour les statuts déclarés dans `RetryPolicy::retryableStatuses`.
- `ProviderPermanentError` pour les autres 4xx (requête invalide, ne
  sert à rien de retry).

Le `RetryPolicy::maxAttempts` borne le retry. Au-delà, le dernier
`ProviderTransientError` est propagé au caller.

### 7. Pas de chiffrement symétrique de payload

Le chiffrement de type « MTN-camo-impot » (AES-256-CBC du payload avec
clé dérivée d'un dynamicSalt) n'est **pas** dans le socle. C'est un
cas rare ; les providers qui en ont besoin écrivent leur propre
`HttpTransport` ou composent les primitives du socle (transport +
auth + body serialization) à l'adapter level.

### 8. La signature sortante et la vérification entrante sont deux
   classes séparées

`RequestSigner` signe les requêtes sortantes (mutates headers).
`WebhookSignatureVerifier` vérifie les signatures des webhooks
entrants. Mêmes primitives HMAC-SHA256 mais interfaces distinctes
parce que les directions et les responsabilités diffèrent (signing
peut muter headers ; verification lit les raw bytes, pas du JSON
parsé).

### 9. Circuit breaker in-memory par défaut

L'implémentation par défaut est in-memory par worker process. C'est
acceptable parce que :

- Un breaker est une machine à états, pas une donnée persistante.
- Le coût d'un état divergent entre workers est borné : un worker peut
  prendre N appels de plus avant de couper.
- L'application shell peut rebinder `CircuitBreaker` à une
  implémentation Valkey-backed pour la coordination cluster quand
  nécessaire (Lot 3+, si on observe des cascades).

### 10. Mode async / saga n'est pas dans le socle

Le guide d'architecture proposait un bus de saga
(`ContinueOperation` / `CompensateOperation`). Ce n'est **pas** dans
le socle — c'est une préoccupation Lot 3 quand un vrai flux async existe
(typiquement MTN MoMo avec callback webhook). Le socle fournit le
synchrone rapide ; les adapters construisent la saga par-dessus quand
besoin.

### 11. Pas de mode `idle` à succès simulé

Rejeté explicitement par [ADR-0026](0026-integration-providers-externes.md).
Le kill switch (`enabled = false`) est le seul mécanisme
d'interruption administrative ; il refuse avec
`ProviderDisabledException`, pas avec un faux succès.

### 12. Pas de table `support.adapter_logs`

Rejeté par [ADR-0026](0026-integration-providers-externes.md) pour
cohérence avec G3d. Les tentatives sont tracées via les logs
structurés (`integration.attempt.completed`, `integration.debug.*`) et
le `correlation_id` qu'ils portent. Quand un module métier (Payment,
Notification) voudra des journaux persistés au-delà des logs, il en
portera la responsabilité dans son propre module.

## Conséquences

- Le socle est **complet pour 90 % des providers MVP** (CinetPay,
  Twilio, Firebase, MTN-cas-simple). Le coût marginal d'un nouvel
  adapter est ~1-3 jours.
- L'OAuth2 token-endpoint flow (MTN-cas-réel) est laissé à l'adapter ;
  il consommera le socle (transport + BasicAuth + TokenCache +
  BearerAuth) sans modification du socle.
- Le DSL de templates (`RequestTemplate` / `ResponseTemplate` /
  catalogue d'endpoints) reste à Lot 3, conçu à partir de deux
  providers réels.
- Le bus de saga reste à Lot 3, conçu à partir d'un vrai flux async.

## Alternatives écartées

- **Tout construire au Lot 0** (DSL templates, catalogue, saga, OAuth2,
  encryption, AdapterLog) — re-figé par ADR-0026.
- **Faire de `BearerAuthProvider` un OAuth2Fetcher** — sur-ingénierie pour
  les providers actuels, et masque l'asymétrie entre Bearer statique et
  Bearer dynamique.
- **Default TokenCache en mémoire** — masquerait silencieusement les
  bugs de refresh token en production. Refusé.
- **Kill switch uniquement par env var lue à chaque appel** — possible
  mais le coût (un `env()` par call) ne vaut pas le bénéfice pour un
  basculement exceptionnel. Le boot-time read est suffisant.
