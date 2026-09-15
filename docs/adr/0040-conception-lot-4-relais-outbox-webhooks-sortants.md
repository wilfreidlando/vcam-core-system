# ADR-0040 — Conception Lot 4 : relais outbox centralisé, webhooks sortants signés en file

- **Statut** : Accepté
- **Date** : 2026-09-15
- **Précise** : [ADR-0009](0009-webhooks-sortants-signes.md) (décision d'origine),
  [ADR-0024](0024-outbox-table-unique-module-events.md) (table outbox unique),
  [ADR-0032](0032-outbox-ecriture-en-parallele-du-lot-2.md) (écriture avancée au Lot 2)

## Contexte

`event_outbox` existe et s'écrit de façon transactionnelle depuis le Lot 2
(ADR-0032), mais rien ne la lit : `Core\Events\Contracts\EventOutbox`
documente lui-même que « le relais qui lit les lignes non publiées est le
Lot 4 ». Le Lot 4 doit livrer ce relais, plus le premier consommateur réel :
le module `Webhook` (endpoints signés HMAC par Project, retry/backoff, DLQ,
replay contrôlé — ADR-0009).

Une contrainte structurelle a été découverte en concevant ce lot : la garde
`NoDirectEventDispatchRule` (`tools/PhpStan/Rules/`), écrite en Phase 0,
réserve déjà explicitement le namespace `Core\Events\Infrastructure\Relay`
comme seul endroit autorisé à appeler les helpers globaux `event()`/
`dispatch()` — avec le commentaire : « le relais est le seul composant dont
le métier est de publier ce que l'outbox a déjà enregistré durablement ».
Cette règle anticipait une décision qui n'avait jamais été prise formellement
: cet ADR la prend.

## Décision

### 1. Un relais unique, possédé par `events` — jamais un par consommateur

`event_outbox.published_at` est une colonne unique par ligne. Si chaque
module consommateur (`webhook` aujourd'hui, `notification`/`billing` demain)
lisait et marquait cette colonne indépendamment, le deuxième consommateur à
tourner verrait des lignes déjà marquées « publiées » par le premier et ne
les traiterait jamais — l'outbox n'est pas un pointeur de lecture par
consommateur, c'est une file à lecteur unique en amont d'un pub/sub interne.

`Core\Events\Infrastructure\Relay\OutboxRelay` (implémentant
`Core\Events\Contracts\RelayOutboxEvents`) est donc le **seul** code qui lit
`event_outbox` et avance `published_at`. Pour chaque ligne non publiée, il
construit le `DomainEvent` et appelle `event(new OutboxEventPublished($event))`
— l'exemption PHPStan déjà en place documente et verrouille ce choix.
`OutboxEventPublished` (le type republié) vit dans `Core\Events\Contracts`,
puisque c'est par ce type que les autres modules s'abonnent (G3c).

Traité par lot borné (défaut 100 lignes), ordonné par `created_at`, chaque
ligne est marquée publiée immédiatement après le `event()` — une lecture qui
crashe avant l'écriture du marqueur peut donc republier une ligne déjà
traitée. C'est accepté : c'est du **at-least-once**, pas du exactly-once, et
chaque consommateur (ici `webhook`) doit donc être idempotent au niveau où
il agit (§4).

### 2. `Webhook` réagit à l'événement republié, il ne relit jamais l'outbox

`Core\Webhook\Presentation\Listeners\FanOutRelayedEventListener` implémente
`ShouldQueue` et s'abonne à `OutboxEventPublished`, enregistré explicitement
dans `WebhookServiceProvider::boot()` (`Event::listen(...)`, pas de
découverte automatique — cohérent avec le reste du dépôt, où chaque module
enregistre lui-même ses routes/bindings). Étant `ShouldQueue`, le traitement
réel (résolution des endpoints, création des livraisons) tourne sur Horizon,
jamais dans le process qui exécute `events:relay`.

### 3. La livraison HTTP ne passe pas par `ExternalApiCaller`

`Core\Support\Integration\ExternalApiCaller` (ADR-0026) est conçu pour un
**petit catalogue de providers** configurés à l'avance
(`ProviderConfigRegistry`), avec retry **en-process** (boucle + `usleep`) et
un disjoncteur par `ProviderCode`. Aucune des deux hypothèses ne tient pour
un webhook sortant : l'URL cible est fournie dynamiquement par le Project
(pas un provider catalogué), et le retry doit survivre un redémarrage de
process et s'étaler sur des heures (`next_retry_at`), pas des millisecondes.

`Core\Webhook\Infrastructure\Delivery\HttpWebhookSender` compose donc
directement les briques déjà publiques de `Core\Support\Integration` —
`Transport\HttpTransport` (le port d'envoi, seul habilité à toucher le
réseau, G6c) et `Signing\HmacSha256RequestSigner` +
`Configuration\SignaturePolicy` (même primitive HMAC-SHA256 que la
signature/vérification des webhooks **entrants** provider) — sans passer par
le pipeline provider. G6c reste respecté : `HttpTransport` est un type de
`Core\Support\Integration`, la seule frontière que la garde interdit de
franchir est un accès direct à `Illuminate\Support\Facades\Http`/Guzzle/PSR
HTTP client, ce qui n'arrive jamais en dehors de `core/support`.

Le retry persisté lui-même est porté par Horizon, pas par une boucle : un
`Core\Webhook\Presentation\Jobs\DeliverWebhookJob` (`ShouldQueue`) appelle
`DeliverWebhookUseCase`, et sur échec transitoire appelle `$this->release($délai)`
avec le délai calculé par `WebhookRetryPolicy` (backoff exponentiel plafonné,
Domain, PHP pur). Après le nombre maximal de tentatives, la livraison passe
`dead` (DLQ) et le job se termine sans nouveau `release()`.

### 4. Idempotence du fan-out : `UNIQUE(endpoint_id, event_id)`

Parce que le relais est at-least-once (§1), le même `OutboxEventPublished`
peut arriver deux fois au listener. `webhook_deliveries` porte une
contrainte unique `(endpoint_id, event_id)` ; une deuxième tentative de
fan-out pour la même paire est absorbée silencieusement (déjà créée), jamais
une deuxième livraison. Chaque tentative HTTP porte aussi l'`event_id`
d'origine en en-tête (`X-Event-Id`), pour que le **consommateur** du webhook
puisse lui-même dédupliquer un retry qu'il aurait déjà reçu (ADR-0009).

### 5. Secret d'endpoint chiffré au repos, jamais réaffiché

`webhook_endpoints.secret` est généré une fois (CSPRNG, même pattern que
`RandomApiKeySecretGenerator` côté Identity) et renvoyé **une seule fois**,
à l'enregistrement de l'endpoint — jamais relu ensuite par l'API. Stocké
chiffré (`Illuminate\Support\Facades\Crypt`, AES via `APP_KEY`), pas haché
comme une clé d'API : contrairement à une clé d'API (dont on ne vérifie
jamais que la correspondance), le secret doit être **relu en clair** à
chaque livraison pour signer le corps sortant — le chiffrement réversible
est donc le bon choix ici, le hachage à sens unique ne l'est pas.

### 6. Enregistrement d'endpoint en self-service HTTP, pas en CLI

Contrairement à l'émission d'une clé d'API (`identity:issue-api-key`,
volontairement CLI-only — une clé d'API est un credential racine), un
endpoint webhook est enregistré par le Project authentifié lui-même via
`POST /v1/webhook-endpoints` (scope `webhooks:write`) : c'est une
configuration applicative, pas un credential d'accès au Core.

### 7. Replay : commande CLI pour l'instant, Core Admin plus tard

ADR-0009 prévoit un replay « contrôlé depuis le Core Admin » — qui n'existe
pas avant le Lot 6. `webhook:replay {delivery}` (commande console, opérateur
uniquement) crée une nouvelle ligne `WebhookDelivery` (tentatives à zéro)
pour le même `(endpoint, event)` et la redispatche — le même déficit assumé
que la réconciliation live de paiement au Lot 3 (ADR-0038 « hors périmètre »),
documenté plutôt que silencieusement absent.

## Conséquences

- `Core\Events\Infrastructure\Relay` est désormais un code réel, pas
  seulement une exemption anticipée dans une règle PHPStan.
- Un futur consommateur de l'outbox (`notification` au Lot 5, `billing`)
  s'abonne au même `OutboxEventPublished` sans que `events` ait besoin de le
  connaître à l'avance — le point d'extension est le bus d'événements
  interne, pas l'outbox elle-même.
- Definition of Done du Lot 4 : un `payment.status_changed` (l'événement
  canonique réellement émis par le Lot 3) livré à un endpoint enregistré,
  avec retry démontrable sur échec puis passage en DLQ après épuisement,
  vérifié par un test d'intégration contre PostgreSQL réel (transport HTTP
  simulé).
