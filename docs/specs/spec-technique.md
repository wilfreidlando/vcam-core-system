> Conversion Markdown de [`source/Spec_Technique_Core_System.docx`](source/Spec_Technique_Core_System.docx), générée par `tools/docs/docx_to_markdown.py`.
> En cas d'écart, le document Word fait foi ; régénérer après toute mise à jour.

# Enterprise Core System — Spécification Technique

Architecture / Conseil logiciel

Version 1.0 — Septembre 2026

## 1. Vue d’ensemble de l’architecture

L’Enterprise Core System est un modular monolith Laravel, structuré en bounded contexts faiblement couplés, exposant des API REST versionnées et communiquant en interne par événements via un transactional outbox. Les intégrations externes (paiement, notification) suivent une architecture Ports & Adapters (hexagonale) : le cœur ne connaît que des interfaces (ports), et chaque provider est un adaptateur interchangeable.

Trois propriétés non négociables gouvernent la conception :

1. Découplage des applications consommatrices — elles n’appellent que les API stables du Core ; elles ignorent tout des providers. Ajouter ou remplacer un provider ne touche jamais une application métier.
1. Intégrité financière — ledger immuable, idempotence par défaut, opérations monétaires atomiques.
1. Extraction progressive — les frontières sont assez nettes pour extraire un module en service indépendant plus tard, sans réécriture.
Le choix du modular monolith plutôt que d’un maillage de microservices dès le départ évite un coût opérationnel prématuré tout en préservant la capacité d’extraction. Pour la volumétrie an 1 visée (< 10 produits, quelques milliers de transactions/jour), un monolithe modulaire large et bien structuré est le bon compromis.

## 2. Décisions d’architecture (résumé)

| # | Décision | Justification |
| --- | --- | --- |
| ADR-01 | Modular monolith Laravel 11 / PHP 8.3 | Coût opérationnel maîtrisé, extraction progressive possible. |
| ADR-02 | Bounded contexts en packages Composer internes (path repositories) | Frontières fortes sans surcoût microservices ; vérifiées par Deptrac. |
| ADR-03 | PostgreSQL comme base transactionnelle et financière | Contraintes fortes, numeric, exclusion constraints, verrous de ligne. |
| ADR-04 | Montants en entiers (minor units, bigint) + code devise | Élimine les erreurs de virgule flottante ; multi-devises propre. |
| ADR-05 | Ledger double-entrée immuable, solde matérialisé | Source de vérité financière auditable ; cohérence stricte. |
| ADR-06 | Concurrence wallet par verrou pessimiste + append atomique | Correction simple et robuste à la volumétrie visée. |
| ADR-07 | Ports & Adapters pour paiement ET notification | Providers interchangeables sans impact sur les apps métier. |
| ADR-08 | Transactional outbox pour les événements | Cohérence état/événement ; pas de perte de message. |
| ADR-09 | Webhooks signés sortants avec retry/backoff/DLQ/replay | Notification fiable des applications consommatrices. |
| ADR-10 | Sanctum (first-party/admin) + Passport OAuth2 (externes) | Deux besoins d’auth distincts, outillage Laravel natif. |
| ADR-11 | Redis + Horizon pour queues, cache, locks | Standard Laravel, observabilité des files intégrée. |
| ADR-12 | Idempotency-Key sur toute mutation monétaire | Rejeu sûr, pas de double effet. |
| ADR-13 | OpenTelemetry + logs structurés + correlation IDs | Traçabilité de bout en bout. |
| ADR-14 | Tenancy par base partagée + scoping organization_id/project_id | Simplicité ; extraction en base dédiée possible pour gros client. |
| ADR-15 | RabbitMQ différé (post-modular-monolith) | N’introduire un bus durable qu’à l’extraction en services. |

## 3. Stack technique

| Couche | Choix |
| --- | --- |
| Langage / framework | PHP 8.3, Laravel 11 |
| Base de données | PostgreSQL 16 |
| Cache / files / locks | Redis 7 + Laravel Horizon |
| Auth | Laravel Sanctum (first-party, admin), Laravel Passport (OAuth2 clients externes) |
| Argent | brick/money (calcul), stockage bigint minor units |
| API / contrats | REST + OpenAPI 3.1 (généré et versionné), erreurs RFC 7807 |
| Bus interne | Événements Laravel + transactional outbox |
| Webhooks | Livraison signée (HMAC), queue dédiée, DLQ |
| Stockage objet | S3-compatible (SDK AWS) |
| Observabilité | OpenTelemetry (traces), Prometheus (metrics), logs JSON structurés |
| Secrets | Gestionnaire de secrets de l’infra + chiffrement applicatif au repos |
| Frontières | Deptrac (règles d’architecture en CI) |
| Tests de contrat | Pact (ou équivalent) entre Core et consommateurs |
| CI/CD | Pipeline avec environnements séparés (sandbox/staging/live) |

## 4. Découpage en bounded contexts

Chaque contexte est un package Composer interne (packages/<context>), avec son namespace, ses migrations, ses modèles, ses services applicatifs et son API. Les contextes ne s’appellent qu’à travers des interfaces publiques et des événements — jamais en accédant directement aux tables d’un autre contexte.

| Module | Responsabilité |
| --- | --- |
| Identity | Organizations, Projects, API keys, OAuth clients, opérateurs, RBAC. |
| Parties | Références d’acteurs (Party) et rattachements. |
| Ledger | Écritures immuables, comptes, soldes matérialisés. |
| Wallet | Wallets, dépôts/retraits/transferts/holds, adossés au Ledger. |
| Payment | Payment intents, orchestration, providers, refunds. |
| Fees | Moteur de frais et commissions, règles, simulation. |
| Billing | Plans, souscriptions, entitlements, usage. |
| Notification | Orchestration multi-canal (email/SMS/WhatsApp/push/in-app), templates, providers. |
| Storage | Fichiers, médias, signed URLs. |
| Audit | Journal des opérations sensibles. |
| Webhook | Endpoints consommateurs, livraison signée, retries, replay. |
| Events | Modèle d’événements, outbox, dispatch. |
| DeveloperPlatform | Gateway, scopes, quotas, portail, OpenAPI, SDK. |

Règle Deptrac : Payment, Wallet, Fees, Billing peuvent dépendre de Ledger, Parties, Events, Identity ; l’inverse est interdit. Aucun module ne dépend d’un adaptateur provider concret.

## 5. Modèle d’identité (technique)

### 5.1 Trois plans

Plan 1 — Opérateurs plateforme. Table operators dans Identity, auth Sanctum, MFA (TOTP), RBAC (roles, permissions, role_permissions, operator_roles).

Plan 2 — Identité machine (applications). Chaque project porte :

- des api_keys (hash stocké, jamais le secret en clair ; préfixe visible pour identification) ;
- des oauth_clients (Passport) avec scopes ;
- un flag d’environnement sandbox / live par credential.
Requête entrante → middleware résout le Project, l’environnement, les scopes, et pose le correlation_id.

Plan 3 — Parties. Table parties légère (voir §6.3). Pas d’authentification end-user. Le produit agit « on-behalf-of » un Party en passant son external_ref.

### 5.2 Assertion « on-behalf-of »

Deux niveaux d’assurance :

- Standard : le Project (authentifié et scopé) affirme agir pour external_ref = X. Le Core fait confiance au Project. Suffisant pour la majorité des cas.
- Renforcé (optionnel) : le produit joint un jeton signé (JWT court, signé avec une clé du Project) attestant l’identité de l’utilisateur final. Utile pour les opérations sensibles où l’on veut une preuve côté produit.

### 5.3 IdP embarqué optionnel

Un Project peut activer un IdP OIDC hébergé par le Core (Passport en mode Authorization Code + PKCE). Opt-in, désactivé par défaut. Dans ce cas seulement, le Core détient des identités end-user pour ce Project précis.

## 6. Modèle de données

Les extraits DDL ci-dessous sont indicatifs (PostgreSQL) et ciblent les tables structurantes. Toutes les tables métier portent organization_id et/ou project_id, created_at, updated_at, et un scope global applicatif.

### 6.1 Identity

```sql
CREATE TABLE organizations (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  name          text NOT NULL,
  status        text NOT NULL DEFAULT 'active',
  created_at    timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE projects (
  id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  organization_id uuid NOT NULL REFERENCES organizations(id),
  name            text NOT NULL,
  status          text NOT NULL DEFAULT 'active',
  created_at      timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE api_keys (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  project_id    uuid NOT NULL REFERENCES projects(id),
  environment   text NOT NULL CHECK (environment IN ('sandbox','live')),
  prefix        text NOT NULL,                 -- partie visible, ex: pk_live_ab12
  key_hash      text NOT NULL,                 -- hash du secret, jamais le secret
  scopes        text[] NOT NULL DEFAULT '{}',
  revoked_at    timestamptz,
  created_at    timestamptz NOT NULL DEFAULT now(),
  UNIQUE (prefix)
);
```

### 6.2 Currencies

```sql
CREATE TABLE currencies (
  code       char(3) PRIMARY KEY,   -- 'XAF', 'USD'
  exponent   smallint NOT NULL      -- XAF = 0, USD = 2
);
```

### 6.3 Parties & Wallets

```sql
CREATE TABLE parties (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  project_id    uuid NOT NULL REFERENCES projects(id),
  external_ref  text NOT NULL,                 -- id de l'utilisateur DANS le produit
  type          text NOT NULL,                 -- customer, end_user, merchant, agent...
  kyc_status    text NOT NULL DEFAULT 'none',
  metadata      jsonb NOT NULL DEFAULT '{}',   -- minimal, jamais de PII imposée
  created_at    timestamptz NOT NULL DEFAULT now(),
  UNIQUE (project_id, external_ref)
);

CREATE TABLE wallets (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  project_id    uuid NOT NULL REFERENCES projects(id),
  party_id      uuid NOT NULL REFERENCES parties(id),
  currency      char(3) NOT NULL REFERENCES currencies(code),
  status        text NOT NULL DEFAULT 'active',
  created_at    timestamptz NOT NULL DEFAULT now(),
  UNIQUE (party_id, currency)
);

-- Solde matérialisé : une ligne par wallet, mise à jour dans la même
-- transaction que les écritures ledger, protégée par verrou de ligne.
CREATE TABLE wallet_balances (
  wallet_id       uuid PRIMARY KEY REFERENCES wallets(id),
  available_minor bigint NOT NULL DEFAULT 0,   -- disponible
  held_minor      bigint NOT NULL DEFAULT 0,    -- réservé (holds)
  version         bigint NOT NULL DEFAULT 0,    -- pour verrou optimiste de secours
  updated_at      timestamptz NOT NULL DEFAULT now(),
  CHECK (available_minor >= 0),
  CHECK (held_minor >= 0)
);
```

### 6.4 Ledger (double-entrée immuable)

```sql
CREATE TABLE ledger_accounts (
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  project_id  uuid NOT NULL REFERENCES projects(id),
  kind        text NOT NULL,          -- wallet, platform_fee, provider_fee, clearing...
  reference   text,                   -- ex: wallet_id, ou compte système
  currency    char(3) NOT NULL REFERENCES currencies(code),
  created_at  timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE ledger_transactions (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  project_id    uuid NOT NULL REFERENCES projects(id),
  type          text NOT NULL,        -- payment, payout, transfer, fee...
  external_ref  text,                 -- rattachement métier (ex: payment_intent_id)
  idempotency_key text,
  created_at    timestamptz NOT NULL DEFAULT now(),
  UNIQUE (project_id, idempotency_key)
);

-- Écritures immuables. Somme des entries d'une transaction = 0 (équilibre).
CREATE TABLE ledger_entries (
  id             bigserial PRIMARY KEY,
  transaction_id uuid NOT NULL REFERENCES ledger_transactions(id),
  account_id     uuid NOT NULL REFERENCES ledger_accounts(id),
  direction      text NOT NULL CHECK (direction IN ('debit','credit')),
  amount_minor   bigint NOT NULL CHECK (amount_minor > 0),
  currency       char(3) NOT NULL REFERENCES currencies(code),
  created_at     timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX ON ledger_entries (account_id);
CREATE INDEX ON ledger_entries (transaction_id);
```

L’équilibre (somme des crédits = somme des débits par transaction) est vérifié applicativement au sein de la transaction et peut être renforcé par un trigger de contrôle. Les ledger_entries ne sont jamais mises à jour ni supprimées ; une correction est une nouvelle transaction compensatoire.

### 6.5 Payment

```sql
CREATE TABLE payment_intents (
  id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  project_id      uuid NOT NULL REFERENCES projects(id),
  environment     text NOT NULL CHECK (environment IN ('sandbox','live')),
  amount_minor    bigint NOT NULL CHECK (amount_minor > 0),
  currency        char(3) NOT NULL REFERENCES currencies(code),
  method          text NOT NULL,       -- mtn_momo, orange_money, card...
  status          text NOT NULL,       -- canonical (voir §9.4)
  external_ref    text,                -- référence produit
  idempotency_key text NOT NULL,
  created_at      timestamptz NOT NULL DEFAULT now(),
  UNIQUE (project_id, idempotency_key)
);

CREATE TABLE provider_transactions (
  id                 uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  payment_intent_id  uuid NOT NULL REFERENCES payment_intents(id),
  provider           text NOT NULL,     -- mtn, orange...
  provider_reference text,              -- id chez le provider
  raw_status         text,              -- statut brut provider
  canonical_status   text NOT NULL,     -- statut normalisé
  attempt            int NOT NULL DEFAULT 1,
  created_at         timestamptz NOT NULL DEFAULT now()
);
```

### 6.6 Fees

```sql
CREATE TABLE fee_rules (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  project_id    uuid REFERENCES projects(id),   -- NULL = règle transverse
  scope         jsonb NOT NULL,   -- conditions: operation, method, country, party_type...
  calculation   jsonb NOT NULL,   -- percent, fixed, min, max, combinaison
  distribution  jsonb NOT NULL,   -- bénéficiaires: platform, agent, partner...
  priority      int NOT NULL DEFAULT 100,
  version       int NOT NULL DEFAULT 1,
  effective_at  timestamptz NOT NULL DEFAULT now(),
  created_at    timestamptz NOT NULL DEFAULT now()
);

-- Règle exacte appliquée à une transaction (historisation).
CREATE TABLE applied_fees (
  id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  transaction_id uuid NOT NULL REFERENCES ledger_transactions(id),
  fee_rule_id    uuid NOT NULL REFERENCES fee_rules(id),
  snapshot       jsonb NOT NULL,   -- copie figée de la règle au moment de l'application
  amount_minor   bigint NOT NULL,
  created_at     timestamptz NOT NULL DEFAULT now()
);
```

### 6.7 Notification

```sql
CREATE TABLE notification_templates (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  project_id    uuid NOT NULL REFERENCES projects(id),
  key           text NOT NULL,             -- ex: booking_confirmed
  channel       text NOT NULL,             -- email, sms, whatsapp, push, in_app
  kind          text NOT NULL DEFAULT 'session'
                CHECK (kind IN ('template','session')), -- WhatsApp: template vs session
  template_status text NOT NULL DEFAULT 'approved',      -- approved, pending
  version       int NOT NULL DEFAULT 1,
  body          jsonb NOT NULL,            -- contenu + variables
  UNIQUE (project_id, key, channel, version)
);

CREATE TABLE notifications (
  id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  project_id     uuid NOT NULL REFERENCES projects(id),
  channel        text NOT NULL,            -- ou 'auto'
  party_ref      text,                     -- external_ref du destinataire
  template_key   text,
  status         text NOT NULL,            -- canonical: queued, sent, delivered, read, failed
  idempotency_key text NOT NULL,
  created_at     timestamptz NOT NULL DEFAULT now(),
  UNIQUE (project_id, idempotency_key)
);

CREATE TABLE notification_deliveries (
  id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  notification_id  uuid NOT NULL REFERENCES notifications(id),
  provider         text NOT NULL,          -- whatsapp_selfhosted, whatsapp_cloud, twilio...
  provider_message_id text,
  raw_status       text,
  canonical_status text NOT NULL,
  attempt          int NOT NULL DEFAULT 1,
  created_at       timestamptz NOT NULL DEFAULT now()
);
```

### 6.8 Webhooks, Outbox, Audit

```sql
CREATE TABLE webhook_endpoints (
  id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  project_id   uuid NOT NULL REFERENCES projects(id),
  url          text NOT NULL,
  secret       text NOT NULL,       -- pour signature HMAC (chiffré au repos)
  events       text[] NOT NULL,     -- abonnements
  active       boolean NOT NULL DEFAULT true
);

CREATE TABLE webhook_deliveries (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  endpoint_id   uuid NOT NULL REFERENCES webhook_endpoints(id),
  event_id      uuid NOT NULL,
  status        text NOT NULL,       -- pending, delivered, failed, dead
  attempt       int NOT NULL DEFAULT 0,
  next_retry_at timestamptz,
  created_at    timestamptz NOT NULL DEFAULT now()
);

-- Transactional outbox : événement écrit dans la MÊME tx que l'état métier.
CREATE TABLE event_outbox (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  project_id    uuid NOT NULL,
  type          text NOT NULL,       -- payment.completed, wallet.credited...
  payload       jsonb NOT NULL,
  correlation_id text,
  published_at  timestamptz,         -- NULL = pas encore relayé
  created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX ON event_outbox (published_at) WHERE published_at IS NULL;

CREATE TABLE audit_log (
  id            bigserial PRIMARY KEY,
  actor         text NOT NULL,       -- operator:xxx, project:xxx, service:xxx
  action        text NOT NULL,
  resource      text NOT NULL,
  before        jsonb,
  after         jsonb,
  context       jsonb,               -- ip, user agent, source
  correlation_id text,
  created_at    timestamptz NOT NULL DEFAULT now()
);
```

## 7. Gestion de l’argent

- Stockage : amount_minor bigint + currency char(3). Aucune colonne monétaire en float/double.
- Calcul : brick/money, qui empêche les opérations entre devises et gère l’arrondi explicitement.
- Précision : la table currencies porte l’exposant ; XAF (exposant 0) n’a pas de sous-unité, ce que le stockage en minor units gère naturellement.
- Arrondi : toute règle produisant une fraction d’unité définit sa stratégie d’arrondi ; l’écart d’arrondi est porté par un compte de clearing dédié dans le ledger, jamais perdu.

## 8. Modèle de concurrence du wallet

Le point le plus sensible techniquement. Deux débits simultanés sur le même wallet ne doivent jamais produire un solde incohérent ni un solde négatif non voulu.

### 8.1 Stratégie retenue — verrou pessimiste + append atomique

Toute mutation de wallet s’exécute dans une seule transaction PostgreSQL :

1. SELECT ... FROM wallet_balances WHERE wallet_id = ? FOR UPDATE — verrouille la ligne de solde.
1. Vérifier la disponibilité (available_minor >= montant pour un débit).
1. Insérer les ledger_entries équilibrées (débit + crédit).
1. Mettre à jour wallet_balances (available/held) et incrémenter version.
1. Écrire l’événement dans event_outbox (même transaction).
1. COMMIT.
Le FOR UPDATE sérialise les opérations concurrentes sur un même wallet ; les opérations sur des wallets différents restent parallèles. À la volumétrie visée, c’est correct et simple.

### 8.2 Pseudo-code

```php
DB::transaction(function () use ($walletId, $amount, $idempotencyKey) {
    // Idempotence : si la clé existe déjà, on renvoie le résultat existant.
    $existing = LedgerTransaction::where('project_id', $projectId)
        ->where('idempotency_key', $idempotencyKey)->first();
    if ($existing) {
        return $existing; // pas de double effet
    }

    // Verrou pessimiste sur la ligne de solde.
    $balance = WalletBalance::where('wallet_id', $walletId)
        ->lockForUpdate()->firstOrFail();

    if ($balance->available_minor < $amount) {
        throw new InsufficientFundsException();
    }

    $tx = LedgerTransaction::create([...]);
    LedgerEntry::create([... 'direction' => 'debit',  ...]);
    LedgerEntry::create([... 'direction' => 'credit', ...]);

    $balance->available_minor -= $amount;
    $balance->version += 1;
    $balance->save();

    EventOutbox::create([
        'type' => 'wallet.debited',
        'payload' => [...],
        'correlation_id' => $correlationId,
    ]);

    return $tx;
});
```

### 8.3 Verrou optimiste de secours

Sous forte contention future, la colonne version permet un verrou optimiste (UPDATE ... WHERE version = ?) avec retry applicatif borné. On ne l’active que si le FOR UPDATE devient un point chaud mesuré.

### 8.4 Holds (réservations)

Un hold déplace un montant de available_minor vers held_minor (même mécanisme atomique). La capture confirme (débit réel), l’annulation libère (retour en available).

## 9. Architecture des adaptateurs providers

Le mécanisme central qui répond à l’objectif : changer ou ajouter un provider sans toucher aux applications métier. Il s’applique de façon identique au paiement et à la notification.

### 9.1 Port (interface commune) — paiement

```php
interface PaymentProvider
{
    public function initiateCollection(CollectionRequest $r): ProviderResult;
    public function initiatePayout(PayoutRequest $r): ProviderResult;
    public function verifyStatus(string $providerReference): ProviderStatus;
    public function handleCallback(CallbackPayload $p): ProviderStatus;
    public function refund(RefundRequest $r): ProviderResult;
    public function supports(PaymentMethod $method, string $country): bool;
}
```

Adaptateurs concrets : MtnMomoAdapter, OrangeMoneyAdapter, (plus tard) CardAcquirerAdapter… Chaque adaptateur encapsule les URLs, l’authentification, le format des requêtes et la traduction des statuts du provider.

### 9.2 Port (interface commune) — notification

```php
interface NotificationChannelProvider
{
    public function send(OutboundMessage $m): ProviderDispatchResult;
    public function getStatus(string $providerMessageId): DeliveryStatus;
    public function handleDeliveryCallback(CallbackPayload $p): DeliveryStatus;
    public function supports(Channel $channel, string $country): bool;
}
```

Adaptateurs concrets par canal et provider :

- WhatsApp : WhatsAppSelfHostedAdapter (instance auto-hébergée), WhatsAppCloudApiAdapter (Meta), TwilioWhatsAppAdapter…
- SMS : agrégateurs / opérateurs locaux.
- Email : SmtpAdapter, SesAdapter, provider transactionnel.
- Push / in-app.

### 9.3 Provider Router & fallback

Un Router sélectionne l’adaptateur en fonction de : la méthode/canal demandé, le provider actif configuré pour le Project, le pays, la disponibilité, et une chaîne de fallback. Si le provider primaire échoue ou est indisponible, le Router bascule vers le secondaire configuré — sans que l’application métier ne le sache.

```php
final class NotificationRouter
{
    /** @var NotificationChannelProvider[] ordre = priorité/fallback */
    public function resolve(Channel $channel, ProjectConfig $cfg): array
    {
        return $cfg->providersFor($channel); // ex: [selfhosted, cloud_api]
    }
}
```

Configuration provider stockée par Project et par environnement (sandbox/live), credentials chiffrés au repos.

### 9.4 Anti-corruption layer & statuts canoniques

Chaque provider a ses propres statuts. L’adaptateur les normalise vers un ensemble canonique unique, seul connu du cœur et des applications.

Paiement (canonique) : pending, processing, succeeded, failed, expired, refunded.

Notification (canonique) : queued, sent, delivered, read, failed.

Ainsi, payment_intents.status et notifications.status sont toujours canoniques ; les statuts bruts restent dans provider_transactions.raw_status / notification_deliveries.raw_status pour l’audit.

### 9.5 Cas spécifique WhatsApp auto-hébergé

Trois contraintes absorbées par l’adaptateur, sans impact sur les apps :

1. Template vs session : WhatsApp distingue les messages template (hors fenêtre 24h, soumis à approbation) des messages de session (dans les 24h après un message entrant). Le modèle notification_templates porte kind (template/session) et template_status (approved/pending). L’adaptateur choisit le bon type d’envoi selon le contexte.
1. Callbacks de statut : l’instance auto-hébergée poste ses accusés (delivered/read) sur un endpoint du Core ; handleDeliveryCallback les traduit en statuts canoniques et déclenche notification.delivered / notification.read.
1. Secrets & environnements : URL et token de l’instance chiffrés au repos, isolés sandbox/live, jamais dans le code métier.
Remplacer l’instance auto-hébergée par un autre provider WhatsApp (ou l’inverse) = activer l’adaptateur cible et changer le provider par défaut du Project dans le Core Admin. Aucune app métier ne bouge.

## 10. Orchestration du paiement (machine à états)

États canoniques d’une Payment Intent : pending → processing → succeeded | failed | expired, avec refunded depuis succeeded.

Flux de référence :

1. Le produit crée une Payment Intent avec clé d’idempotence.
1. Le Core valide montant, devise, projet, environnement, credentials.
1. Le Fee Engine calcule frais et distributions applicables (simulation puis figeage).
1. Le Router choisit le provider ; l’adaptateur déclenche la collecte.
1. Le provider renvoie un résultat immédiat et/ou un callback.
1. Le Core vérifie le statut auprès du provider si nécessaire (verifyStatus).
1. À la confirmation, le Core enregistre les écritures au ledger (transaction atomique) et applique les distributions.
1. Le Core publie payment.completed / payment.failed via l’outbox.
1. Le Notification Core envoie les notifications nécessaires.
1. Toutes les références et traces sont conservées pour audit et réconciliation.
Timeouts et retries sont bornés ; un paiement resté processing au-delà d’un seuil déclenche une vérification active puis, si nécessaire, une réconciliation.

## 11. Fee & Commission Engine

- Les fee_rules portent scope (conditions d’application), calculation (percent/fixed/min/max), distribution (bénéficiaires), priority, version, effective_at.
- L’évaluation sélectionne les règles applicables par scope, ordonne par priorité, calcule le montant et le plan de distribution.
- Le plan de distribution se traduit en écritures ledger multiples dans une seule transaction : débit payeur, crédit bénéficiaire, crédit frais plateforme, crédit commission agent, frais provider.
- La règle appliquée est figée dans applied_fees.snapshot (copie immuable), garantissant la reproductibilité et l’auditabilité.
- La simulation permet de prévisualiser l’effet d’une règle avant activation.

## 12. Événements & Outbox

### 12.1 Transactional outbox

L’événement est inséré dans event_outbox dans la même transaction que le changement d’état métier. Un relayeur (job Horizon) lit les lignes published_at IS NULL, dispatche vers les consommateurs internes (listeners) et vers le module Webhook, puis marque published_at. Aucune perte, aucun événement sans état correspondant.

### 12.2 Catalogue d’événements (extrait)

| Événement | Émis quand |
| --- | --- |
| payment.completed | Paiement confirmé et enregistré au ledger. |
| payment.failed | Paiement échoué / expiré. |
| payment.refunded | Remboursement effectué. |
| wallet.credited | Wallet crédité. |
| wallet.debited | Wallet débité. |
| payout.completed | Versement effectué. |
| payout.failed | Versement échoué. |
| subscription.renewed | Souscription renouvelée. |
| subscription.expiring | Souscription proche de l’échéance. |
| notification.delivered | Notification livrée par le provider. |
| notification.read | Notification lue (WhatsApp). |
| notification.failed | Notification échouée après fallbacks. |

### 12.3 Webhooks sortants

- Livraison signée (HMAC via webhook_endpoints.secret), en-tête de signature + timestamp.
- Idempotence côté consommateur via un event_id stable.
- Retry avec backoff exponentiel, next_retry_at, passage en dead après N échecs (DLQ), et replay contrôlé depuis le Core Admin.

## 13. Conception des API

- Versionnement par URL (/v1/...) ; changements incompatibles → nouvelle version, avec politique de dépréciation et fenêtre de préavis.
- Idempotence : header Idempotency-Key obligatoire sur toutes les mutations monétaires et d’envoi.
- Corrélation : header Correlation-Id propagé de bout en bout (généré si absent).
- Erreurs : format RFC 7807 (application/problem+json) avec type, title, status, detail, correlation_id.
- Pagination : par curseur pour les grandes collections.
- Contrats : OpenAPI 3.1 généré, publié dans le portail développeur, testé par tests de contrat (Pact) entre Core et consommateurs.
Exemple — création d’un paiement :

```http
POST /v1/payments
Authorization: Bearer <project_live_key>
Idempotency-Key: 9f1c...-a2
Content-Type: application/json

{
  "amount": 5000,
  "currency": "XAF",
  "method": "mtn_momo",
  "on_behalf_of": "user_8421",
  "external_ref": "order_1245",
  "metadata": { "order_id": "1245" }
}
```

Exemple — envoi d’une notification (identique quel que soit le provider derrière) :

```http
POST /v1/notifications
Authorization: Bearer <project_live_key>
Idempotency-Key: 51ab...-7c

{
  "channel": "whatsapp",
  "to_party_ref": "user_8421",
  "template": "booking_confirmed",
  "variables": { "name": "Awa", "date": "2026-10-02" }
}
```

## 14. Sécurité

- Moindre privilège : scopes fins par credential ; un client externe n’obtient que le strict nécessaire.
- Isolation : organization_id/project_id sur toutes les requêtes via global scopes ; contrôle systématique de l’appartenance.
- Secrets : jamais en clair dans le code ; credentials providers et secrets webhooks chiffrés au repos ; rotation supportée.
- Webhooks : signature HMAC sortante ; vérification de signature et protection anti-rejeu sur les callbacks providers entrants.
- Rate limiting & quotas : par Project et par environnement.
- Sandbox / live : isolation totale des données et des effets.
- Validation : validation stricte des entrées (Form Requests), rejet des payloads non conformes.
- Accès production restreint : opérations critiques auditées, MFA opérateurs.
- Chiffrement en transit : TLS de bout en bout.

## 15. Observabilité et exploitation

- Correlation ID / Request ID sur chaque requête et opération.
- Logs structurés JSON, corrélés.
- Metrics par capability et par provider (taux de succès, latence, backlog de files, callbacks, réconciliation).
- Traces distribuées (OpenTelemetry) lorsque plusieurs modules interviennent.
- Alertes sur taux d’échec, latence, backlog, DLQ, écarts de réconciliation.
- Health / readiness checks ; supervision des files Horizon et de la DLQ.

## 16. Multi-tenancy & isolation

- Base partagée, isolation applicative par organization_id/project_id (global scopes Eloquent, jamais désactivables silencieusement).
- Option d’activer PostgreSQL Row-Level Security en défense en profondeur.
- Extraction en base dédiée possible pour un gros client externe (exigence contractuelle), sans changer les contrats API.

## 17. Déploiement & infrastructure

- Conteneurs (application, workers Horizon, relayeur outbox), orchestrés sur le cloud cible.
- Environnements séparés : sandbox, staging, live, avec credentials et bases distincts.
- Migrations versionnées, jouées en pipeline ; migrations financières irréversibles traitées avec précaution (pas de rollback destructeur).
- CI/CD : lint, tests unitaires, tests de contrat, vérification Deptrac, build image, déploiement progressif.
- Files : Horizon pour jobs applicatifs, files dédiées pour webhooks et notifications (isolation des pannes).

## 18. Stratégie de test

- Tests unitaires sur les règles de frais, le calcul monétaire, la machine à états.
- Invariants ledger : propriétés vérifiées (somme des entries = 0 par transaction ; solde matérialisé = somme dérivée des entries).
- Tests de concurrence : débits parallèles sur un même wallet ne produisent ni solde négatif ni double effet.
- Mocks providers : chaque adaptateur testé contre un simulateur (sandbox) reproduisant statuts et callbacks, y compris cas d’échec et timeout.
- Tests de contrat (Pact) entre Core et applications consommatrices pour garantir la compatibilité ascendante.
- Tests d’idempotence : rejeu de requêtes identiques.

## 19. Périmètre et séquencement du MVP

Dans le MVP :

- Foundation : Identity (Org/Project/API keys/OAuth), Parties (lazy), Audit, observabilité de base.
- Money : Ledger, Wallet (crédit/débit/hold), idempotence.
- Payment : orchestration + adaptateurs MTN MoMo et Orange Money, XAF, Cameroun, sandbox/live.
- Fees : moteur de règles + distribution + simulation.
- Events & Webhooks : outbox, webhooks signés, retry/DLQ/replay.
- Notification : orchestration + adaptateur WhatsApp auto-hébergé (+ un canal de secours), templates, statuts canoniques.
- Migration : shadow mode pour un produit pilote.
Hors MVP (phases ultérieures) : Billing/Subscriptions complet, Storage/Media avancé, providers carte, Developer Platform externe et SDK publics, extraction en services, RabbitMQ.

Séquencement recommandé : Foundation → Money/Payment → Notifications → Billing → Developer Platform → migration progressive des produits.

## 20. Risques techniques & mitigations

| Risque | Mitigation |
| --- | --- |
| Core trop large / fourre-tout | Architecture par capabilities, comité d’architecture, critères d’entrée. |
| Couplage excessif des consommateurs | API/événements uniquement, aucun accès direct aux tables, tests de contrat. |
| Règles financières incorrectes | Ledger immuable, idempotence, invariants testés, réconciliation. |
| Point chaud de contention wallet | Verrou pessimiste ciblé ; bascule optimiste si mesuré. |
| Divergence de statuts providers | Anti-corruption layer, statuts canoniques, raw_status conservé. |
| Panne d’un provider | Fallback configuré, isolation des files, dégradation gracieuse. |
| Migration risquée d’un produit en prod | Shadow mode / dual-write, réconciliation avant bascule, strangler pattern. |
| Microservices prématurés | Modular monolith d’abord, extraction justifiée par la mesure. |
| Fuite de secrets via intégrations | Credentials isolés, chiffrés au repos, scopes, signatures, audit. |

## 21. Annexe — Décisions à confirmer avant Phase 2 (Money)

- Statut réglementaire : modèle agrégateur via providers agréés vs détention de valeur pour tiers (licence e-money). À trancher avant d’ouvrir les wallets à des tiers.
- Politique d’arrondi par devise et compte de clearing associé.
- Politique de dépréciation des versions d’API (fenêtre de préavis).
- Stratégie de sauvegarde/restauration et RPO/RTO du sous-système financier.

Fin de la Spécification Technique — Version 1.0

---

# Addendum 1.0 — Plateforme multi-tenant de projets SaaS (2026-10-24)

> **Addendum daté, statut proposé.** Le document Word
> `source/Spec_Technique_Core_System.docx` reste la référence des §1 à §21
> ci-dessus ; plusieurs de ses éléments ont depuis été remplacés par des ADR
> (Passport → clés d'API, sandbox/live → environnement unique ADR-0066,
> `notification_templates` supprimée ADR-0043). Cet addendum décrit l'état
> cible des ADR-0068 à ADR-0090.
>
> **Règle anti-divergence** : le DDL complet d'une table est écrit **une seule
> fois**, dans l'ADR qui la décide (colonne « Référence »). Cet addendum fait
> foi pour l'**inventaire** (tables, routes, événements, portées, erreurs,
> écritures, jobs, garde-fous) et donne le DDL des seules tables qu'aucun ADR
> ne détaille.

## T1. Modules et graphe

| Module | Pilier | Statut | Dépendances (`ModuleGraph.php`) |
|---|---|---|---|
| `billing` | commerce | scaffold → production | `support`, `identity`, `payment`, `ledger`, `events` (inchangé) |
| `portal` | commerce | **nouveau** | `support`, `identity`, `billing`, `payment`, `notification`, `audit` |
| `payout` | grand livre | **nouveau** | `support`, `identity`, `ledger`, `wallet`, `parties`, `events`, `payment` |
| `notification` | services | modifié | + `billing` (port `QuotaGuard`, ADR-0074) |
| `developer-platform` | services | scaffold → production minimale | inchangé |
| `admin` | services | modifié | + `payout`, `portal` |
| `fees`, `storage` | — | scaffolds, hors périmètre | inchangés |

Aucun cycle. `ledger` sans dépendance (G2). Artefacts à faire concorder (G1,
G1b) : `ModuleGraph.php`, `deptrac-modules.yaml`, `composer.json` (module et
racine), règle PHPStan `ModulesMeetOnlyThroughContracts`.

## T2. Inventaire des tables

| Table | Module | Référence | Notes clés |
|---|---|---|---|
| `organization_memberships` | identity | ADR-0069 §1 | une appartenance active par (organisation, compte) |
| `operator_invitations` | identity | T3.1 | jeton d'activation haché, 72 h |
| `api_keys` (modifiée) | identity | T3.2 | nom, portées, CIDR, expiration, dernier usage ; fin de l'unicité par projet |
| `customers` | billing | ADR-0070 §1 | PII chiffrée, index aveugles, liste blanche de colonnes |
| `plans`, `plan_versions` | billing | ADR-0071 §1 | versions publiées immuables (trigger) |
| `subscriptions`, `subscription_periods` | billing | ADR-0072 §1 | contrainte d'exclusion des périodes, une période `current` |
| `invoices`, `invoice_lines`, `invoice_number_sequences` | billing | ADR-0073 §1 | immuables à l'émission, numérotation sans trou |
| `invoice_payments` | billing | ADR-0073 §2 | `payment_id` unique, une tentative `pending` par facture |
| `manual_receipts` | billing | ADR-0073 §4 | quatre yeux en base |
| `usage_records`, `usage_idempotency`, `usage_counters` | billing | ADR-0074 §1 | journal partitionné par mois |
| `billing_settings` | billing | T3.3 | réglages commerciaux par projet |
| `billing_daily_metrics` | billing | ADR-0080 §3 | instantanés quotidiens |
| `portal_settings`, `portal_sessions`, `portal_login_codes` | portal | T3.4 | jetons et codes hachés |
| `money_exceptions` | payment | ADR-0077 §3 | file d'exceptions d'argent |
| `reconciliation_runs` | payment | ADR-0078 §5 | |
| `payouts`, `payout_settings` | payout | T3.5 | destination chiffrée |
| `ledger_transactions` (modifiée) | ledger | T3.6 | `reverses_transaction_id`, immuable |
| `audit_log` (modifiée) | audit | T3.7 | trigger d'immuabilité, empreinte quotidienne |
| `exports` | admin | ADR-0088 §3 | fichiers chiffrés, 7 jours |
| `capabilities` (registre) | developer-platform | ADR-0087 §1 | fichier versionné, pas une table |

Conventions : UUID v7, `timestamptz` UTC, montants `bigint` + `currency char(3)`
(G5), `project_id NOT NULL` sur **toute** table de données de projet, aucune
clé étrangère inter-modules (ADR-0033 §5) — les clés étrangères **intra-module**
sont autorisées et utilisées.

## T3. DDL des tables non détaillées par un ADR

### T3.1 `operator_invitations`

```sql
CREATE TABLE operator_invitations (
  id              uuid PRIMARY KEY,
  organization_id uuid,                     -- NULL : invitation de personnel de plateforme
  email           text NOT NULL,
  role            text NOT NULL,
  token_hash      text NOT NULL UNIQUE,
  invited_by      uuid NOT NULL,
  expires_at      timestamptz NOT NULL,
  accepted_at     timestamptz,
  revoked_at      timestamptz,
  created_at      timestamptz NOT NULL DEFAULT now()
);
```

### T3.2 `api_keys` (évolution)

```sql
ALTER TABLE api_keys DROP CONSTRAINT api_keys_one_per_project;
ALTER TABLE api_keys
  ADD COLUMN name          text NOT NULL DEFAULT 'clé principale',
  ADD COLUMN allowed_cidrs cidr[],
  ADD COLUMN expires_at    timestamptz,
  ADD COLUMN last_used_at  timestamptz,
  ADD COLUMN last_used_ip  inet,
  ADD COLUMN revoke_reason text;
-- borne « au plus N clés actives par projet » vérifiée à l'émission (N réglable, défaut 10)
```

### T3.3 `billing_settings`

```sql
CREATE TABLE billing_settings (
  project_id                               uuid PRIMARY KEY,
  timezone                                 text NOT NULL DEFAULT 'Africa/Douala',
  invoice_prefix                           text NOT NULL,
  default_tax_rate_bps                     integer NOT NULL DEFAULT 0 CHECK (default_tax_rate_bps BETWEEN 0 AND 10000),
  renewal_lead_days                        integer NOT NULL DEFAULT 7 CHECK (renewal_lead_days BETWEEN 0 AND 60),
  incomplete_expiry_hours                  integer NOT NULL DEFAULT 72,
  manual_receipt_approval_threshold_minor  bigint,
  require_customer_ref                     boolean NOT NULL DEFAULT false,
  refund_failed_usage                      boolean NOT NULL DEFAULT true,
  retain_invoice_identity                  boolean NOT NULL DEFAULT true,
  meter_mappings                           jsonb NOT NULL DEFAULT '{}',  -- {"notification.whatsapp": "whatsapp_messages"}
  document_branding                        jsonb NOT NULL DEFAULT '{}',
  updated_at                               timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT billing_settings_prefix_format CHECK (invoice_prefix ~ '^[A-Z0-9]{2,12}$')
);
```

### T3.4 `portal_*`

```sql
CREATE TABLE portal_settings (
  project_id            uuid PRIMARY KEY,
  slug                  text NOT NULL UNIQUE CHECK (slug ~ '^[a-z0-9-]{3,40}$'),
  display_name          text NOT NULL,
  logo_url              text CHECK (logo_url IS NULL OR logo_url ~ '^https://'),
  primary_color         text CHECK (primary_color IS NULL OR primary_color ~ '^#[0-9a-f]{6}$'),
  support_url           text,
  allowed_return_urls   text[] NOT NULL DEFAULT '{}',
  direct_login_enabled  boolean NOT NULL DEFAULT false,
  allow_self_cancel     boolean NOT NULL DEFAULT false,
  allow_plan_change     boolean NOT NULL DEFAULT false,
  payment_channels      text[] NOT NULL DEFAULT '{}',
  updated_at            timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE portal_sessions (
  id              uuid PRIMARY KEY,
  project_id      uuid NOT NULL,
  customer_id     uuid NOT NULL,
  link_token_hash text NOT NULL UNIQUE,   -- HMAC du jeton du lien
  link_expires_at timestamptz NOT NULL,
  consumed_at     timestamptz,
  session_id_hash text UNIQUE,            -- HMAC de l'identifiant de cookie après échange
  idle_expires_at timestamptz,
  absolute_expires_at timestamptz,
  created_by      text NOT NULL,          -- 'api_key:<id>' | 'direct_login'
  return_url      text,
  flow            jsonb NOT NULL DEFAULT '{}',
  revoked_at      timestamptz,
  created_at      timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE portal_login_codes (
  id            uuid PRIMARY KEY,
  project_id    uuid NOT NULL,
  customer_id   uuid NOT NULL,
  code_hash     text NOT NULL,
  attempts      integer NOT NULL DEFAULT 0 CHECK (attempts <= 5),
  expires_at    timestamptz NOT NULL,
  consumed_at   timestamptz,
  created_at    timestamptz NOT NULL DEFAULT now()
);
```

### T3.5 `payouts`, `payout_settings`

```sql
CREATE TABLE payouts (
  id                    uuid PRIMARY KEY,
  project_id            uuid NOT NULL,
  party_id              uuid NOT NULL,
  wallet_id             uuid NOT NULL,
  amount_minor          bigint NOT NULL CHECK (amount_minor > 0),
  currency              char(3) NOT NULL,
  channel               text NOT NULL,          -- canal de versement canonique
  provider_code         text NOT NULL,
  destination_ciphertext text NOT NULL,
  destination_masked    text NOT NULL,
  idempotency_key       text NOT NULL,
  status                text NOT NULL,
  provider_reference    text,
  requested_by          text NOT NULL,          -- 'api_key:<id>' | 'operator:<id>'
  approved_by           uuid,
  reason                text,
  failure_reason        text,
  created_at            timestamptz NOT NULL DEFAULT now(),
  updated_at            timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT payouts_status_check CHECK (status IN
    ('pending_approval', 'pending', 'processing', 'succeeded', 'failed', 'canceled', 'reversed')),
  CONSTRAINT payouts_idem UNIQUE (project_id, idempotency_key)
);

CREATE TABLE payout_settings (
  project_id                    uuid PRIMARY KEY,
  max_amount_minor              bigint,
  daily_limit_minor             bigint,
  approval_threshold_minor      bigint,
  new_destination_hold_minutes  integer NOT NULL DEFAULT 0,
  updated_at                    timestamptz NOT NULL DEFAULT now()
);
```

### T3.6 Grand livre

```sql
ALTER TABLE ledger_transactions ADD COLUMN reverses_transaction_id uuid;
-- trigger : refuse UPDATE/DELETE sur ledger_transactions (comme ledger_entries)
-- trigger : refuse une ledger_entry dont le compte n'a pas le project_id de la transaction (G13)
```

### T3.7 Journal d'audit

```sql
-- trigger : refuse UPDATE/DELETE sur audit_log
CREATE TABLE audit_log_digests (
  day        date PRIMARY KEY,
  digest     text NOT NULL,        -- SHA-256(digest de la veille || entrées du jour triées)
  created_at timestamptz NOT NULL DEFAULT now()
);
```

## T4. Routes publiques ajoutées (`/v1`, `project.auth`)

| Route | Portée | Idempotence |
|---|---|---|
| `PUT /v1/customers/{external_ref}` · `GET` | `customers:write` · `customers:read` | structurelle ; `If-Match` optionnel |
| `GET /v1/customers` | `customers:read` | — |
| `POST /v1/customers/{ref}/suspend` · `/reactivate` · `/erase` | `customers:write` | structurelle |
| `GET /v1/customers/{ref}/entitlements` · `/export` | `customers:read` | — |
| `GET /v1/plans` · `POST /v1/plans` · `POST /v1/plans/{code}/versions` · `…/versions/{n}/publish` · `POST /v1/plans/{code}/archive` | `plans:read` / `plans:write` | `Idempotency-Key` sur les `POST` |
| `POST /v1/subscriptions` · `GET /v1/subscriptions` · `GET /v1/subscriptions/{id}` | `subscriptions:write` / `:read` | `Idempotency-Key` |
| `POST /v1/subscriptions/{id}/cancel` · `/change-plan` | `subscriptions:write` | transition conditionnelle |
| `POST /v1/invoices` · `GET /v1/invoices` · `GET /v1/invoices/{id}` · `…/document` | `invoices:write` / `:read` | `Idempotency-Key` |
| `POST /v1/invoices/{id}/issue` · `/pay` · `/void` | `invoices:write` | `Idempotency-Key` (G7 sur `/pay`) |
| `POST /v1/usage` · `GET /v1/usage` | `usage:write` / `:read` | `idempotency_key` dans le corps (domaine, comme ADR-0056) |
| `POST /v1/portal/sessions` | `portal:write` | jeton à usage unique |
| `POST /v1/payouts` · `GET /v1/payouts` · `GET /v1/payouts/{id}` | `payouts:write` / `:read` | `Idempotency-Key` (G7) |
| `POST /v1/webhook-endpoints/{id}/rotate-secret` | `webhooks:write` | — |
| `POST /v1/exports` · `GET /v1/exports/{id}` | `exports:write` | — |
| `GET /v1/capabilities` | aucune portée | — |
| `POST /v1/test-clock/advance` | `subscriptions:write` ; staging + projet `test_mode` | — |
| `GET /v1/{payments, notifications, webhook-endpoints}` (listings) | `:read` correspondant | — |

Routes du portail (hôte `portal.*`, session `portal.session`) : accueil,
abonnements (annulation à échéance, changement de plan selon réglages),
factures (lecture, document, paiement), paiements et reçus, usage, profil.
Routes console : `/v1/admin/*` étendues (organisations, membres, clients,
catalogue, abonnements et gestes, factures, encaissements hors ligne,
exceptions, rapprochement, versements et approbations, portefeuille, exports),
toutes sous `operator.auth` + `AccessScope`.

## T5. Portées d'API

Existantes (10) : `payments:read/write`, `webhooks:read/write`,
`notifications:read/write`, `notification_channel_sessions:read/write`,
`wallets:read/write`. Ajoutées : `customers:read/write`, `plans:read/write`,
`subscriptions:read/write`, `invoices:read/write`, `usage:read/write`,
`portal:write`, `payouts:read/write`, `exports:write`. Les clés existantes ne
reçoivent **pas** automatiquement les nouvelles portées (ADR-0082 §2).

## T6. Événements ajoutés

Enveloppe existante (ADR-0063) + `api_version` (ADR-0086). Charges utiles sans
donnée personnelle ; toute ressource de `billing` porte `version`.

| Famille | Types |
|---|---|
| Clients | `customer.created`, `customer.updated`, `customer.suspended`, `customer.reactivated`, `customer.erased` |
| Catalogue | `plan.version_published`, `plan.archived` |
| Abonnements | `subscription.created`, `subscription.trial_will_end`, `subscription.activated`, `subscription.renewal_due`, `subscription.renewed`, `subscription.past_due`, `subscription.paused`, `subscription.resumed`, `subscription.plan_changed`, `subscription.canceled`, `subscription.expired` |
| Factures | `invoice.created`, `invoice.issued`, `invoice.paid`, `invoice.payment_failed`, `invoice.voided`, `invoice.uncollectible` |
| Usage | `usage.threshold_reached`, `usage.limit_reached` |
| Versements | `payout.status_changed` |
| Contrat | `api.deprecation_scheduled` |
| Paiement (modifié) | `payment.status_changed` gagne `confirmed_amount` et `exception` (additifs) |

Tous écrits dans l'outbox, dans la transaction de l'état (G8, G8c).

## T7. Erreurs ajoutées (RFC 7807, `correlation_id`)

| Statut | `type` | Quand |
|---|---|---|
| 403 | `project-suspended` | projet suspendu |
| 403 | `key-origin-not-allowed` | origine hors CIDR de la clé |
| 403 | `entitlement-inactive` | abonnement sans droits |
| 403 | `feature-not-included` | fonctionnalité absente du plan |
| 404 | `customer-not-found` | client inconnu **ou d'un autre projet** |
| 409 | `invalid-state-transition` | transition interdite ou concurrente |
| 409 | `plan-version-immutable` | modification d'une version publiée |
| 409 | `invoice-not-payable` | facture non ouverte, ou tentative déjà en attente |
| 409 | `insufficient-funds` | solde du wallet insuffisant (aligné sur ADR-0065) |
| 409 | `insufficient-provider-balance` | position `clearing` insuffisante pour un versement |
| 409 | `last-owner` | retrait du dernier propriétaire d'organisation |
| 412 | `version-conflict` | `If-Match` périmé |
| 422 | `customer-ref-required` | route mesurée sans client alors que l'attribution est obligatoire |
| 422 | `return-url-not-allowed` | URL de retour hors liste |
| 422 | `provider-application-missing` | versement sans application prestataire du projet |
| 429 | `quota-exceeded` | limite commerciale atteinte (sans `Retry-After`) |
| 429 | `rate-limit-exceeded` | protection de débit (avec `Retry-After`) |

## T8. Idempotence

| Niveau | Où | Clé |
|---|---|---|
| HTTP (G7) | toute mutation monétaire : `/v1/payments/*`, `/v1/invoices/{id}/pay`, `/v1/payouts`, portail (jeton de formulaire) | `Idempotency-Key`, portée `(project_id, clé)` |
| Domaine | notifications (ADR-0056), usage | `client_reference` / `idempotency_key` |
| Grand livre | toute transaction | clé dérivée de l'objet : id du paiement pour son règlement (partagée avec le crédit de wallet, donc un seul règlement par paiement), `invoice:<id>:issue`, `invoice_payment:<id>:allocate`, `manual_receipt:<id>`, `payout:<id>:reserve`… |
| Structurelle | `PUT /v1/customers/{ref}`, transitions d'état conditionnelles, consommation des jetons | la ressource elle-même |

## T9. Matrice des écritures (ADR-0076)

| Fait | Débit | Crédit | Module |
|---|---|---|---|
| Paiement confirmé, `purpose=wallet_credit` | `clearing:<p>` | `wallet:<partie>` | payment (via wallet) |
| Paiement confirmé, `purpose=invoice` | `clearing:<p>` | `collections` | payment |
| Paiement confirmé, `purpose=direct` | `clearing:<p>` | `revenue` | payment |
| Paiement confirmé, montant ou devise en écart | `clearing:<p>` (montant confirmé) | `payment_exceptions` | payment |
| Imputation d'un paiement à une facture | `collections` | `customer_receivable:<client>` | billing |
| Surpaiement ou paiement tardif d'une facture déjà payée | `collections` | `payment_exceptions` | billing |
| Émission d'une facture | `customer_receivable:<client>` | `revenue` | billing |
| Annulation d'une facture | `revenue` | `customer_receivable:<client>` | billing |
| Encaissement hors ligne | `manual_receipts:<moyen>` | `customer_receivable:<client>` | billing |
| Irrécouvrable | `bad_debt` | `customer_receivable:<client>` | billing |
| Résolution d'exception : imputer à une facture | `payment_exceptions` | `customer_receivable:<client>` | billing |
| Résolution d'exception : imputer à un wallet / rembourser / revenu | `payment_exceptions` | `wallet` / `withdrawal` / `revenue` | payment |
| Versement réservé | `wallet:<partie>` | `payout_in_transit:<p>` | payout |
| Versement confirmé | `payout_in_transit:<p>` | `clearing:<p>` | payout |
| Versement échoué / annulé | `payout_in_transit:<p>` | `wallet:<partie>` | payout |
| Versement retourné | `clearing:<p>` | `wallet:<partie>` | payout |
| Frais du prestataire (relevé) | `provider_fees:<p>` | `clearing:<p>` | payment |
| Transfert entre wallets (existant) | `wallet:<a>` | `wallet:<b>` | wallet |
| Sortie enregistrée (existant) | `wallet:<partie>` | `withdrawal` | wallet |
| Correction d'exploitation | selon le cas | selon le cas, via `adjustment` | admin (double validation) |

## T10. Travaux planifiés

| Commande | Cadence | Garde |
|---|---|---|
| `events:relay` (existant) | après chaque commit + chaque minute | `SKIP LOCKED`, plusieurs workers |
| `billing:advance-subscriptions` | 5 min | `onOneServer`, `withoutOverlapping`, `Clock` par projet |
| `billing:daily-metrics` | quotidien | idempotent par `(projet, jour, devise)` |
| `invariants:check` | horaire | lecture seule ; contrôleurs de `payment` et `billing` (ADR-0078 §2) |
| `reconciliation:run --level=transactions|balance` | par cycle prestataire | `onOneServer` |
| `payouts:sweep` | 1 min | `SKIP LOCKED` |
| `partitions:ensure` | quotidien | crée les partitions des 3 mois suivants |
| `privacy:purge` | quotidien | rétentions d'ADR-0083 |
| `audit:digest` | quotidien | chaînage d'empreintes |
| `admin:reconcile-stuck-payments` (existant) | 1 min | inchangé (ADR-0067) |

## T11. Compatibilité avec les règles dures

| Règle | Comment elle est tenue |
|---|---|
| G2 | `ledger` reste sans dépendance ; ses nouveaux ports sont en lecture, consommés par les autres |
| G3b | agrégats `Customer`, `Plan`, `Subscription`, `Invoice`, `UsageCounter`, `Payout` en PHP pur ; ports à scalaires |
| G3c | `notification`, `portal`, `payout`, `admin` n'atteignent `billing`/`payment` que par `Contracts/` |
| G3d | chiffrement et index aveugle dans `support` sans persistance |
| G4 | inchangé sur `parties` ; liste blanche de colonnes sur `customers` |
| G5 | tous les montants ajoutés sont `bigint` + `char(3)` (`price_minor`, `total_minor`, `amount_minor`…) ; les compteurs d'usage sont des quantités `bigint` |
| G6 / G6b / G6c | statuts canoniques `SubscriptionStatus`, `InvoiceStatus`, `PayoutStatus` protégés ; montant confirmé lu par l'adaptateur seul ; sorties HTTP par `Integration` |
| G7 | `Idempotency-Key` sur `/invoices/{id}/pay` et `/payouts` ; portail par jeton de formulaire |
| G8 / G8c | tous les événements par l'outbox, dans la transaction de l'état |
| G9 | ajouts additifs ; étendu à `openapi/admin.json` (ADR-0086) |
| **G13** (nouveau) | trigger d'isolation inter-projets du grand livre |
| **G14** (nouveau) | aucun champ secret dans une réponse hors liste « affiché une fois » |
| **G15** (nouveau) | tests IDOR générés pour toute route à identifiant |

## T12. Ce qui n'est pas construit par cet addendum

Rien : c'est une spécification. Les lots du plan de delivery (addendum du
même jour) construisent ces éléments un par un, chacun après l'acceptation des
ADR qu'il réalise.

Fin de l'addendum — 2026-10-24
