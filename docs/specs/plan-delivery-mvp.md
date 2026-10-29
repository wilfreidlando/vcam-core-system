> Conversion Markdown de [`source/Plan_Delivery_MVP_Core_System.docx`](source/Plan_Delivery_MVP_Core_System.docx), générée par `tools/docs/docx_to_markdown.py`.
> En cas d'écart, le document Word fait foi ; régénérer après toute mise à jour.

# Enterprise Core System — Plan de Delivery du MVP

Architecture / Conseil logiciel

Version 1.0 — Septembre 2026

## 1. Objet du document

Ce document est le plan opérationnel de construction du MVP de l’Enterprise Core System. Il traduit les Spécifications Fonctionnelle et Technique en lots de travail, avec dépendances, séquencement, jalons, structure de code concrète, critères de terminaison (Definition of Done) et stratégie de mise en production du produit pilote.

Il répond à trois questions : qui construit quoi, dans quel ordre, et comment sait-on qu’un lot est fini.

### 1.1 Hypothèses de cadrage

| Paramètre | Valeur retenue |
| --- | --- |
| Équipe | 1 tech lead/architecte (≈50 %), 3 à 4 développeurs backend, 1 QA (≈50 %) |
| Durée MVP visée | ≈ 20 semaines (5 mois) |
| Périmètre providers | MTN MoMo + Orange Money (paiement), WhatsApp auto-hébergé (notification) |
| Devise / pays | XAF / Cameroun |
| Cadence | Sprints de 2 semaines, revue de fin de sprint, démo par lot |
| Cloud cible | Cloud compatible AWS (S3, secrets manager, conteneurs) |

Ces hypothèses conditionnent les estimations. Un changement d’effectif ou de périmètre décale proportionnellement les jalons.

## 2. Rappel du périmètre MVP

### 2.1 Dans le MVP

- Foundation : Identity (Organizations, Projects, API keys, OAuth clients, opérateurs, RBAC), Parties (lazy), Audit, observabilité de base.
- Money : Ledger double-entrée, Wallet (crédit/débit/hold), idempotence.
- Payment : orchestration + adaptateurs MTN MoMo et Orange Money, sandbox/live.
- Fees : moteur de règles + distribution + simulation.
- Events & Webhooks : transactional outbox, webhooks signés, retry/backoff/DLQ/replay.
- Notification : orchestration + adaptateur WhatsApp auto-hébergé (+ un canal de secours), templates, statuts canoniques.
- Core Admin (minimal) : projets, credentials, transactions, wallets, règles de frais, providers, webhooks, audit, réconciliation.
- Migration pilote en shadow mode.

### 2.2 Hors MVP (phases ultérieures)

Billing/Subscriptions complet, Storage/Media avancé, providers carte, Developer Platform externe et SDK publics, IdP end-user embarqué, extraction en services, RabbitMQ.

> **Caduque sur deux points** (addendum du 2026-10-24, §P3, ratifié le
> 2026-10-27). « Billing/Subscriptions complet » **rejoint le périmètre** : le
> module `billing` entre en production au lot 10 (ADR-0070 à ADR-0074), et
> `developer-platform` avec (ADR-0068 §Conséquences). `storage` et `fees`
> restent hors périmètre — ADR-0048, amendé par ADR-0068. En cas de
> contradiction, **l'addendum daté l'emporte** sur le §2.2 ci-dessus.

## 3. Organisation et gouvernance

### 3.1 Rôles

| Rôle | Responsabilité |
| --- | --- |
| Tech Lead / Architecte | Garde les frontières (Deptrac), valide les contrats API et le modèle ledger, arbitre les décisions. |
| Dev « Money » | Ledger, Wallet, concurrence, Fees. Le profil le plus rigoureux de l’équipe. |
| Dev « Payment/Providers » | Orchestration, adaptateurs MTN/Orange, réconciliation. |
| Dev « Platform » | Identity, Events/Outbox, Webhooks, Core Admin. |
| Dev « Notification » (ou mutualisé) | Orchestration notification, adaptateur WhatsApp. |
| QA | Tests de contrat, tests de concurrence, scénarios sandbox providers. |

### 3.2 Comité d’architecture

Un comité léger (tech lead + 1 pair) valide toute demande d’entrée d’un domaine dans le Core et toute évolution de contrat d’API. C’est le garde-fou contre la dérive « fourre-tout ».

### 3.3 SLA interne envers les équipes produit

Pour que le Core reste politiquement viable en interne, l’équipe plateforme s’engage sur un délai de traitement des demandes des équipes consommatrices (ex. réponse sous 2 jours, évolution mineure de contrat sous un sprint). À défaut, les produits contournent le Core et l’investissement est perdu.

## 4. Découpage en lots de travail

Huit lots. Chaque lot produit un incrément démontrable et testable.

### Lot 0 — Socle projet

Contenu : dépôt, structure en packages Composer (path repositories), configuration Deptrac, environnements sandbox/staging/live, CI/CD (lint, tests, Deptrac, build image), observabilité de base (logs structurés, correlation ID, health checks), squelette PostgreSQL + Redis + Horizon.

Sortie : un « hello capability » traversant l’authentification Project et écrivant un log corrélé.

### Lot 1 — Identity & Foundation

Contenu : Organizations, Projects, API keys (hash + préfixe), OAuth clients (Passport, scopes), séparation sandbox/live, opérateurs + RBAC, module Audit, middleware de résolution Project/environnement/scopes.

Sortie : création d’une Organization, d’un Project, génération de credentials, appel authentifié tracé et audité.

### Lot 2 — Money Core (Ledger + Wallet + Parties)

Contenu : table currencies, Parties (création lazy), Ledger double-entrée immuable, soldes matérialisés, Wallet (crédit/débit/hold) avec verrou pessimiste + append atomique, idempotence, invariants ledger.

Sortie : créditer/débiter un wallet de façon cohérente et idempotente ; tests de concurrence verts. C’est le lot le plus critique — il ne démarre pas tant que le modèle de données ledger/wallet n’est pas validé par le comité d’architecture.

### Lot 3 — Payment & Fees

Contenu : Payment Intents + machine à états, interface PaymentProvider, adaptateurs MtnMomoAdapter et OrangeMoneyAdapter (sandbox d’abord), Provider Router, anti-corruption layer + statuts canoniques, moteur de frais (fee_rules, évaluation, distribution, simulation, applied_fees), enregistrement des écritures au ledger via Lot 2.

Sortie : un produit crée un paiement via l’API Core, le Core route vers le provider, calcule les frais, enregistre au ledger, distribue.

### Lot 4 — Events & Webhooks

Contenu : transactional outbox, relayeur (job Horizon), catalogue d’événements, module Webhook (endpoints, signature HMAC, retry/backoff, DLQ, replay), abonnements par Project.

Sortie : payment.completed/payment.failed publiés et livrés à une application via webhook signé, avec retry en cas d’échec.

### Lot 5 — Notification & WhatsApp

Contenu : interface NotificationChannelProvider, orchestration multi-canal, WhatsAppSelfHostedAdapter (+ un canal de secours), Notification Router + fallback, templates versionnés (template vs session, template_status), callbacks de statut entrants, statuts canoniques.

Sortie : un produit envoie une notification WhatsApp via l’API Core sans connaître le provider ; changer de provider WhatsApp n’impacte pas l’app.

### Lot 6 — Core Admin & Réconciliation

Contenu : interface d’administration minimale (projets, credentials, transactions, wallets/ledger, règles de frais, providers, webhooks, audit), écrans de réconciliation (rapprochement Core ↔ provider), monitoring opérationnel et DLQ.

Sortie : un opérateur pilote la plateforme, rejoue un webhook, consulte l’audit et réconcilie une journée de transactions.

### Lot 7 — Migration pilote & Go-live

Contenu : intégration du produit pilote, shadow mode (double exécution ancienne/nouvelle implémentation, sans effet réel côté nouveau), réconciliation automatique des écarts, bascule progressive (canary), dépréciation de l’ancienne implémentation.

Sortie : le produit pilote encaisse en production via le Core, avec réconciliation démontrée avant bascule complète.

## 5. Dépendances entre lots

| Lot | Dépend de | Peut démarrer en parallèle de |
| --- | --- | --- |
| Lot 0 | — | — |
| Lot 1 | Lot 0 | — |
| Lot 2 | Lot 1 (Project, isolation) | Lot 4 (partie infra outbox) |
| Lot 3 | Lot 2 (ledger), Lot 1 | — |
| Lot 4 | Lot 1 ; couplé à Lot 3 pour les événements paiement | Lot 2 (fin) |
| Lot 5 | Lot 4 (outbox/événements) | Lot 6 (début) |
| Lot 6 | Lots 1–4 | Lot 5 |
| Lot 7 | Tous | — |

Chemin critique : Lot 0 → Lot 1 → Lot 2 → Lot 3 → Lot 4 → Lot 7. Le Money Core (Lot 2) est le nœud de risque : tout retard s’y propage.

## 6. Jalons et séquencement (≈ 20 semaines)

| Semaines | Lot(s) actif(s) | Jalon |
| --- | --- | --- |
| S1–S2 | Lot 0 | M0 — Socle en place, CI/CD verte, Deptrac actif. |
| S3–S5 | Lot 1 | M1 — Identity opérationnelle, credentials sandbox/live. |
| S5–S9 | Lot 2 | M2 — Money Core : wallet cohérent, tests de concurrence verts. |
| S9–S13 | Lot 3 | M3 — Paiement bout-en-bout en sandbox (MTN + Orange) avec frais. |
| S11–S14 | Lot 4 | M4 — Événements + webhooks signés fiables. |
| S13–S16 | Lot 5 | M5 — Notification WhatsApp via Core, fallback vérifié. |
| S15–S18 | Lot 6 | M6 — Core Admin + réconciliation utilisables. |
| S17–S20 | Lot 7 | M7 — Pilote en shadow mode, puis go-live canary. |

Les chevauchements reflètent la parallélisation possible avec 3–4 développeurs. Une revue de risque est tenue à M2 et à M7.

## 7. Structure de code Laravel

### 7.1 Principe

Un modular monolith : une seule application Laravel, mais chaque bounded context est un package Composer interne dans packages/, référencé via path repositories. Les frontières sont vérifiées automatiquement par Deptrac en CI. Aucun module n’accède aux tables d’un autre : uniquement via interfaces publiques et événements.

### 7.2 Arborescence cible

```text
core-system/
├── app/                          # coquille Laravel (bootstrap, providers globaux)
├── bootstrap/
├── config/
├── database/
│   └── migrations/               # migrations transverses uniquement
├── packages/
│   ├── identity/
│   │   ├── composer.json
│   │   ├── src/
│   │   │   ├── Models/
│   │   │   ├── Http/             # controllers, requests, resources
│   │   │   ├── Services/         # logique applicative
│   │   │   ├── Contracts/        # interfaces publiques du module
│   │   │   ├── Events/
│   │   │   └── IdentityServiceProvider.php
│   │   ├── database/migrations/
│   │   └── tests/
│   ├── parties/
│   ├── ledger/
│   ├── wallet/
│   ├── payment/
│   │   ├── src/
│   │   │   ├── Contracts/
│   │   │   │   └── PaymentProvider.php      # le PORT
│   │   │   ├── Providers/                   # les ADAPTATEURS
│   │   │   │   ├── Mtn/MtnMomoAdapter.php
│   │   │   │   └── Orange/OrangeMoneyAdapter.php
│   │   │   ├── Routing/PaymentRouter.php
│   │   │   ├── StateMachine/
│   │   │   ├── Http/
│   │   │   └── PaymentServiceProvider.php
│   │   └── ...
│   ├── fees/
│   ├── notification/
│   │   ├── src/
│   │   │   ├── Contracts/
│   │   │   │   └── NotificationChannelProvider.php   # le PORT
│   │   │   ├── Providers/
│   │   │   │   └── WhatsApp/WhatsAppSelfHostedAdapter.php
│   │   │   ├── Routing/NotificationRouter.php
│   │   │   └── ...
│   ├── events/                   # outbox + dispatch
│   ├── webhook/
│   ├── storage/
│   ├── audit/
│   └── admin/                    # Core Admin
├── routes/
│   └── api.php                   # agrège les routes exposées par les modules
├── tests/
│   ├── Contract/                 # tests de contrat (Pact)
│   └── Integration/
├── deptrac.yaml                  # règles de frontières
└── composer.json                 # path repositories vers packages/*
```

### 7.3 composer.json — path repositories

```json
{
  "repositories": [
    { "type": "path", "url": "packages/*" }
  ],
  "require": {
    "php": "^8.3",
    "laravel/framework": "^11.0",
    "laravel/sanctum": "^4.0",
    "laravel/passport": "^12.0",
    "brick/money": "^0.9",
    "core/identity": "*",
    "core/ledger": "*",
    "core/wallet": "*",
    "core/payment": "*",
    "core/notification": "*"
  }
}
```

Chaque package déclare son ServiceProvider, enregistré automatiquement, qui publie ses routes, migrations et bindings d’interfaces.

### 7.4 deptrac.yaml — frontières (extrait)

```yaml
deptrac:
  paths: [ ./packages ]
  layers:
    - name: Identity
      collectors: [ { type: directory, value: packages/identity/.* } ]
    - name: Ledger
      collectors: [ { type: directory, value: packages/ledger/.* } ]
    - name: Wallet
      collectors: [ { type: directory, value: packages/wallet/.* } ]
    - name: Payment
      collectors: [ { type: directory, value: packages/payment/.* } ]
    - name: Fees
      collectors: [ { type: directory, value: packages/fees/.* } ]
  ruleset:
    Payment: [ Ledger, Fees, Identity, Parties, Events ]
    Wallet:  [ Ledger, Parties, Events ]
    Fees:    [ Ledger, Parties ]
    Ledger:  [ ]            # le Ledger ne dépend de personne
    Identity: [ ]
```

Une dépendance non déclarée fait échouer la CI. Le Ledger, cœur financier, ne dépend d’aucun autre module — c’est la règle la plus importante.

### 7.5 Liaison Port ↔ Adaptateur

Dans le ServiceProvider du module Payment, les adaptateurs sont enregistrés et résolus par le Router selon la configuration du Project :

```php
public function register(): void
{
    $this->app->tag(
        [MtnMomoAdapter::class, OrangeMoneyAdapter::class],
        'payment.providers'
    );

    $this->app->bind(PaymentRouter::class, function ($app) {
        return new PaymentRouter($app->tagged('payment.providers'));
    });
}
```

Ajouter un provider = créer une classe implémentant PaymentProvider, la tagger, et l’activer dans la config du Project. Aucune application consommatrice ne change.

## 8. Environnements et CI/CD

| Environnement | Usage |
| --- | --- |
| Sandbox | Tests d’intégration, providers en mode simulateur, aucun argent réel. |
| Staging | Pré-production, données réalistes anonymisées, providers en sandbox. |
| Live | Production, providers réels, accès restreint et audité. |

Pipeline CI (sur chaque merge request) : lint → tests unitaires → tests de contrat → Deptrac → build image → déploiement sandbox. Les migrations financières (irréversibles) sont revues manuellement et jouées de façon contrôlée. Un déploiement live passe par staging et une approbation.

## 9. Definition of Done (par lot)

Un lot n’est « fini » que si tous les points ci-dessous sont vrais :

- Code revu (au moins un pair), frontières Deptrac respectées (CI verte).
- Tests unitaires et d’intégration passants ; pour Money : invariants ledger et tests de concurrence passants.
- Contrats d’API publiés en OpenAPI et couverts par des tests de contrat.
- Idempotence vérifiée sur toutes les mutations concernées.
- Événements émis via l’outbox (pas d’émission hors transaction).
- Opérations sensibles présentes dans l’audit, corrélées.
- Documentation du module à jour (README + endpoints).
- Démo réalisée en fin de sprint.
Definition of Done spécifique Money (Lot 2) : aucune écriture ledger mutable ; solde matérialisé toujours égal à la somme dérivée des écritures ; aucun solde négatif non voulu sous concurrence ; rejeu d’une opération identique sans double effet.

## 10. Stratégie de migration du produit pilote

### 10.1 Choix du pilote

Un produit transactionnel disposant de paiements, frais/commissions, notifications et administration — assez riche pour démontrer le ROI, sans exposer d’emblée les opérations les plus critiques.

### 10.2 Shadow mode

1. Le produit continue d’utiliser son implémentation actuelle (source de vérité).
1. En parallèle, chaque opération est aussi envoyée au Core, sans effet réel côté Core (mode observation).
1. Un job de réconciliation compare en continu les résultats des deux systèmes et signale les écarts.
1. Quand l’écart est nul sur une période représentative, on bascule.

### 10.3 Bascule progressive (canary)

Bascule d’un pourcentage croissant du trafic vers le Core (par exemple 5 % → 25 % → 100 %), avec possibilité de retour arrière immédiat. L’ancienne implémentation reste disponible jusqu’à confirmation, puis est dépréciée.

### 10.4 Règle d’or

Aucune bascule d’un flux d’argent réel sans réconciliation à zéro écart préalable. C’est ce qui évite un incident financier au premier client migré.

## 11. Risques delivery et mitigations

| Risque | Impact | Mitigation |
| --- | --- | --- |
| Modèle ledger sous-estimé | Retard sur le chemin critique | Valider le modèle Lot 2 avant tout code ; revue de risque à M2. |
| Sandbox provider instable ou tardive | Blocage Lot 3 | Simulateurs internes reproduisant statuts/callbacks ; ne pas dépendre du provider réel pour avancer. |
| Contention wallet en charge | Incidents financiers | Verrou pessimiste ciblé, tests de concurrence, bascule optimiste si mesuré. |
| Scope creep (produits qui poussent du métier dans le Core) | Dérive fourre-tout | Comité d’architecture, critères d’entrée, périmètre MVP figé. |
| Équipe plateforme goulot d’étranglement | Perte d’adhésion interne | SLA interne, documentation, contrats stables. |
| Question réglementaire wallets tiers non tranchée | Blocage juridique Phase 2 | Trancher le statut (agrégateur vs e-money) avant d’ouvrir les wallets à des tiers. |
| Migration pilote risquée | Incident en production | Shadow mode + réconciliation zéro écart + canary réversible. |

## 12. Suivi de l’avancement

Indicateurs suivis à chaque fin de sprint :

- Lots terminés vs planifiés (respect du chemin critique).
- Couverture des tests de contrat par capacité.
- Taux de succès paiement en sandbox par provider.
- Écarts de réconciliation en shadow mode (tendance vers zéro).
- Backlog DLQ et taux d’échec webhooks.
- Dette de frontières (violations Deptrac) — doit rester à zéro.

## 13. Checklist Go-live (produit pilote)

- ☐ Credentials live provisionnés et testés (MTN, Orange, WhatsApp).
- ☐ Webhooks du produit configurés et signature vérifiée.
- ☐ Règles de frais live validées et simulées.
- ☐ Réconciliation shadow mode à zéro écart sur période représentative.
- ☐ Audit et observabilité opérationnels (alertes actives).
- ☐ DLQ et procédure de replay testées.
- ☐ Plan de retour arrière (canary) documenté et éprouvé.
- ☐ Astreinte et procédure d’incident financier définies.
- ☐ Décision réglementaire tranchée si des wallets tiers sont exposés.

## 14. Suite après MVP

Une fois le pilote stabilisé : Billing/Subscriptions complet, Developer Platform externe (portail, SDK, quotas), Storage/Media avancé, providers additionnels (carte), puis extraction en services des modules dont la charge ou le domaine le justifient (introduction de RabbitMQ à ce moment-là seulement). Chaque nouveau produit branché est un incrément, plus une reconstruction.

Fin du Plan de Delivery du MVP — Version 1.0

---

# Addendum 1.0 — Plan de delivery de la plateforme de projets SaaS (2026-10-24)

> **Addendum daté, ratifié.** Le document Word
> `source/Plan_Delivery_MVP_Core_System.docx` reste la référence des §1 à §14
> ci-dessus. Cet addendum découpe les ADR-0068 à ADR-0091 en lots. Aucun lot
> ne commence avant l'acceptation par le comité des ADR qu'il réalise — la
> série 0068→0090 a été **ratifiée le 2026-10-27**, et ADR-0091 consigne les
> décisions que le code avait introduites sans ADR. Le §2.2 ci-dessus est
> **caduque** sur deux points : voir la note en fin de §2.2.

## P1. Situation de départ

Lots 0, 1, 2, 4, 5 et 6 livrés ; le **lot 3 livré pour son orchestration
seulement** — le moteur de frais et les adaptateurs MTN/Orange directs
restent absents, hors périmètre de production (ADR-0048) ; les **lots 8, 9,
10 et 11 livrés**, le 11 le 2026-10-28. Le lot 7 (« migration pilote ») est
remplacé par le lot 17 (bascule et extinction), puisqu'aucune donnée n'est à
reprendre (ADR-0089, sous réserve de confirmation par la direction).

## P2. Principes

1. **Un lot = un incrément démontrable de bout en bout**, avec ses tests et sa
   recette ; jamais une tranche de couche.
2. **Chaque lot se termine vert** : `make ci`, `make guard-test`, `make arch`,
   `make stan` ; `openapi/v1.json` (et `admin.json`) régénérés sans rupture.
3. **Sécurité incluse, pas ajoutée** : chaque lot livre les contrôles S-xx de
   ses ADR et passe la revue de sécurité (skill `security-review`) avant
   fusion.
4. **Le graphe de modules change avec le lot qui crée l'arête**, après
   acceptation de l'ADR correspondant (comité, ADR-0002).
5. **Estimations** en jours de travail d'un développeur senior, ±30 %, tests
   et documentation inclus.

## P3. Les lots

| Lot | Contenu | ADR | Dépend de | Est. |
|---|---|---|---|---|
| **8 — Fondations de sécurité et d'identité** | appartenances d'organisation, `AccessScope` obligatoire dans tous les ports console (migration des ports existants), invitations par jeton, cycle de vie des projets ; clés multiples à portée, rotation, CIDR ; service de chiffrement et d'index aveugle dans `support` ; trigger d'immuabilité et empreinte de `audit_log` ; garde-fous **G14** et **G15** | 0069, 0081, 0082, 0083 (socle) | — | 12 j |
| **9 — Grand livre complet** | `purpose` des paiements, écriture de tout paiement confirmé, `clearing:<prestataire>` et reclassement, vérification du montant confirmé, `payment_exceptions` et file `money_exceptions`, trigger inter-projets (**G13**), immuabilité des transactions, ports de lecture par période et balance, contrôles d'invariants de `payment` (`invariants:check`) | 0076, 0077, 0078 (niveau A) | — | 10 j |
| **10 — Commerce I : clients, catalogue, abonnements** | module `billing` en production ; `customers` (PII chiffrée), `plans`/`plan_versions`, `subscriptions`/`subscription_periods`, machine d'états, planificateur sur `Clock`, `billing_settings`, événements, API publique, écrans console de base | 0070, 0071, 0072 | 8 | 14 j |
| **11 — Commerce II : factures et encaissement** | factures, numérotation, documents HTML (le PDF attend sa bibliothèque — ADR-0094 §14), `invoice_payments`, imputation au grand livre, encaissement hors ligne validé à un compte (ADR-0095), surpaiements en exception, **enregistrement** des reçus (le rendu du reçu reste à écrire — ADR-0073 §5) | 0073, 0094, 0095 | 9, 10 | 12 j |
| **12 — Usage et quotas** | `usage_records` partitionné, `usage_idempotency`, `usage_counters`, `POST /v1/usage`, `entitlements`, port `QuotaGuard` de `notification` (arête `notification → billing`), seuils dans la transaction, compensation des échecs | 0074 | 10 | 9 j |
| **13 — Portail client** | module `portal` : sessions créées par le projet, échange et cookie, personnalisation, écrans (abonnements, factures, paiement, reçus, usage, profil), connexion directe par code (désactivée par défaut), en-têtes de sécurité testés | 0075 | 8, 11 | 13 j |
| **14 — Console de portefeuille** | écrans Portefeuille / Projet / Client / Créances / Exceptions, indicateurs, `billing_daily_metrics`, liste de contrôle d'onboarding, gestes d'exploitation d'abonnement | 0080, 0072 §6 | 11, 12 | 10 j |
| **15 — Exploitation** | débit par projet / clé / classe, en-têtes `RateLimit-*`, métriques par projet, tableaux Grafana et alertes par budget d'erreur, relais de l'outbox après commit, partitions, listings publics par curseur, exports | 0085, 0088, 0090 | 8 | 10 j |
| **16 — Contrat et expérience développeur** | politique de version (`Deprecation`/`Sunset`, G9 sur `admin.json`, énumérations ouvertes), `api_version` dans les événements, changelog au tag, registre de capacités testé, pages de démarrage exécutées, horloges de test | 0086, 0087 | 10 | 7 j |
| **17 — Bascule et extinction** | guide d'intégration unique, recette bout-en-bout avec une application réelle, bascule projet par projet, coexistence 14 jours, coupure, retrait | 0084, 0089 | 11, 12, 13 | 8 j + 4 semaines calendaires |
| **18 — Rapprochement prestataire** | capacités `listTransactions` et `balance` des adaptateurs, niveaux B et C, `reconciliation_runs`, écrans | 0078 | 9 | 10 j |
| **19 — Versements sortants** | module `payout`, port et adaptateur avec environnement de test du prestataire, callback dédié, balayage, plafonds, double validation, destination chiffrée | 0079 | 9 | 15 j |

**État au 2026-10-28** : les **lots 8, 9, 10, 11, 12 et 13 sont livrés** (le 12 : ADR-0074 ; le 13 : ADR-0075 et ADR-0097 — la connexion directe par code, entrée secondaire d'ADR-0075 §2, reste à faire) — code conforme à
leurs ADR, `make ci` et `make guard-test` verts. La série d'ADR qui les
réalise a été **ratifiée par le comité le 2026-10-27**, après vérification de
la conformité du code livré (la décision a suivi le code, et non l'inverse —
c'est tracé dans ADR-0068 §6 et dans ADR-0091). Les lots 9 et 10, qui ne
dépendent de rien d'autre, sont terminés ; le lot 11 — la facture et son
encaissement (ADR-0073, ADR-0094, ADR-0095) — l'est également.

**État du lot 9** : il est **livré** (le 2026-10-27). L'écriture de tout
paiement confirmé, la vérification du montant confirmé, la file
`money_exceptions` et sa résolution à quatre yeux (ADR-0076 §3, ADR-0077),
le trigger d'isolation inter-projets et l'immuabilité des transactions
(ADR-0076 §5), les lectures par période, la balance et le relevé de compte
(`ListLedgerTransactions`, `TrialBalance`, `AccountStatement`, ADR-0076 §4),
les corrections d'exploitation à quatre vues et leur contre-passation
obligatoire (ADR-0076 §6), le compte `clearing` **par prestataire** et son
reclassement motivé (ADR-0076 §2 — sans lui, le rapprochement par
prestataire et les versements sont impossibles), le contrôle horaire des
invariants (`invariants:check`, ADR-0078 §2) et le registre des
rapprochements (`reconciliation_runs`, ADR-0078 §5) sont en place et testés.

Le lot 9 livre aussi la **clôture de S-07** (ADR-0081) : un compte console
n'est plus ouvert par un mot de passe choisi par le système, mais par un
jeton d'activation à usage unique — l'invitation est révocable, expirante,
et le mot de passe est choisi par l'invité, à l'activation.

**État du lot 10** : il est **livré** (le 2026-10-27). Le module `billing`
est en production : les **clients** d'un projet (identité minimale
chiffrée, index aveugle, effacement qui détruit l'identité et garde la
ligne — ADR-0070, ADR-0083), le **catalogue** de plans et de versions
(une version publiée est figée par un trigger de base, ADR-0071), les
**abonnements** et leurs périodes (machine d'états, exclusion des
périodes qui se chevauchent, verrou optimiste — ADR-0072), l'ordonnanceur
`billing:advance-subscriptions` sur `Clock`, les réglages par projet
(`billing_settings`), l'**API publique** (16 routes `/v1`, un
`FormRequest` par mutation, un corps documenté) et la **console** (les
clients, les offres et les abonnements d'un projet ; chaque geste est
audité — ADR-0092 §2 et §4). Le **lot 11** ajoute la **facture** et son
**encaissement** (ADR-0073, ADR-0094, ADR-0095) : un document figé à
l'émission, numéroté sans trou, taxé par ligne, dont l'émission inscrit la
créance au grand livre et dont l'annulation la contre-passe ; puis le
paiement **en ligne** (`POST /v1/invoices/{id}/pay`, l'intention `invoice`
qui écarte le double comptage, ADR-0094 §9) et l'**encaissement hors
ligne** — un reçu qui porte sa méthode, sa référence et son montant, et
qui attend une validation explicite au-dessus du seuil du projet
(ADR-0094 §13 ; ADR-0095 a retiré les quatre yeux : un seul compte
valide). Un règlement partiel n'est **jamais** imputé (ADR-0094 §10) et un
paiement tardif sur une facture déjà soldée part en **surpaiement** plutôt
que de disparaître. La déclaration du paiement d'une période refuse
désormais une période dont la facture n'est pas soldée (ADR-0094 §8).

**Charge**. Les lots 8 à 19 pèsent **130 jours de travail** au total. Les
lots 12 à 19 — ceux qui restent — pèsent **82 j** (9 + 13 + 10 + 10 + 7 + 8
+ 10 + 15). C'est **plus que le budget que ce plan s'était fixé** : le plan
imputait le dépassement du lot 11 sur l'avenir, ce qui n'est pas une réserve
mais un trou. Il faut absorber 4 j, ou réduire la charge.

**Ce qui est mesurable, et ce qui ne l'est pas.** Les lots 8 à 11 sont
livrés en **4 jours calendaires** (3 jours ouvrés, du 26 au 28 octobre 2026),
en 15 commits et 71 055 lignes ajoutées sur 949 fichiers. Il n'existe
**aucune trace d'effort** dans le dépôt : les « 52 j déjà consommés » que ce
plan annonçait étaient une estimation rebadigotée, pas une mesure, et le
« 16 j sur 12 » du lot 11 n'a rien derrière lui non plus. Les estimations
par lot sont donc **non contraignantes** : le calendrier se pilote sur les
dates réelles, la charge se pilote sur le nombre de personnes.

## P4. Chemin critique et parallélisation

```
Lot 8 ─────────► Lot 10 ──► Lot 11 ──► Lot 13 ──► Lot 17 (bascule)
                   │          ▲  │
Lot 9 ─────────────┼──────────┘  └──► Lot 14
  │                └──► Lot 12 ───────────────────▲ (17 dépend aussi de 12)
  ├──► Lot 18
  └──► Lot 19
Lot 15 (dès la fin du lot 8)       Lot 16 (dès la fin du lot 10)
```

- **Chemin critique** : 8 → 10 → 11 → 13 → 17 = 12 + 14 + 12 + 13 + 8 =
  **59 jours** de travail, puis 4 semaines de bascule. Les lots 8, 10 et 11
  étant livrés, il reste **21 jours** sur ce chemin (13 + 8).
- **Second chemin critique** : 8 → 10 → 11 → 12 → 14 = 12 + 14 + 12 + 9 + 10
  = **57 jours** — à deux jours du premier. Les deux voies se tiennent **sans
  marge** : le lot 17 dépend du lot 12 (§P3), et le lot 14 en dépend
  aussi. C'est la voie de B qui est tendue, pas celle de A.
- **Seconde personne** : le lot **12 d'abord** — c'est lui qui contraint le
  lot 17 — puis 15, 14 et 16. Voir §P7.
- Les lots 18 et 19 ne conditionnent pas la bascule : ils sont reportables.

## P5. Critères de sortie communs

- Les tests de vérification listés dans chaque ADR du lot passent.
- Les critères de recette de la spécification fonctionnelle (addendum, §F8)
  correspondants sont verts sur le staging.
- Revue de sécurité faite, constats critiques et hauts corrigés.
- Documentation d'intégration de la capacité écrite et exemples `curl`
  exécutés par la suite de tests.

## P6. Risques et parades

| Risque | Parade |
|---|---|
| Le comité n'accepte pas un ADR qui modifie le graphe (0068, 0074, 0075, 0079) | **levé le 2026-10-27** : la série est ratifiée, les arêtes de graphe des lots 12, 13 et 19 sont donc autorisées. Le risque rouvre si une de ces décisions change |
| Le prestataire ne fournit pas le montant confirmé, le relevé ou le solde | capacités d'adaptateur déclarées (ADR-0077, ADR-0078) ; le core fonctionne en mode dégradé visible, jamais présumé juste |
| Migration des ports console vers `AccessScope` plus longue que prévu | elle est en tête (lot 8) et vérifiée par une règle PHPStan |
| Charge du journal d'usage | partitionnement dès la première migration ; test de charge au lot 15 |
| Recette bout-en-bout dépendante d'une vraie session de messagerie et d'un vrai numéro | session créée par l'exploitant, numéro de l'équipe, simulateur de paiement et horloge de test |
| Confirmation de « aucune donnée à reprendre » non obtenue | plan de reprise partielle (clients, plans) prévu dans le plan de bascule |

## P7. Calendrier prévisionnel

Hypothèses : deux développeurs seniors (A sur le chemin critique, B en
parallèle), jours ouvrés, hors 25 décembre, 1ᵉʳ janvier et 11 février. Le
comité a ratifié la série le 2026-10-27 et les **lots 8, 9, 10 et 11 sont
livrés**.

**Rebasage du 2026-10-28.** Le lot 11 a été livré le 2026-10-28, **seize
jours calendaires avant** sa date planifiée (le 13 novembre). Le plan
prévoyait sa propre règle : « un lot en retard de plus de 20 % sur son
estimation déclenche une replanification écrite dans cet addendum ». La
règle s'applique, mais sur un autre fondement : il n'existe **aucune mesure
d'effort** dans le dépôt, donc le calendrier se rebase sur la **date réelle**
du lot 11, pas sur une charge estimée.

Ce rebasage fait deux choses :

- il **libère A** : le lot 13 démarre le 2026-10-29 au lieu du 2026-11-16 ;
- il **corrige la voie de B** : le lot 12 passe **en tête**, parce que le lot
  17 en dépend (§P3) et qu'il était le vrai facteur bloquant de la bascule.
  Le plan d'origine faisait commencer le lot 17 avant la fin du lot 12 — une
  incohérence qu'un premier rebasage avait reprise sans la voir.

Résultat : **extinction le 29 décembre 2026**, vingt jours avant la date
publiée. A est engagée **sans interruption** du 2026-10-29 au 2026-12-29,
sans marge ; B aussi, jusqu'au 2026-12-22. Les dates glissent d'autant qu'un
lot déborde ; chaque lot reste conditionné à l'acceptation de ses ADR.

| Étape | Qui | Début | Fin |
|---|---|---|---|
| Ratification de la série ADR-0068 à ADR-0091 par le comité | direction + comité | 2026-10-26 | 2026-10-27 |
| Lot 8 — Fondations de sécurité et d'identité | A | 2026-10-24 | 2026-10-27 (**livré**) |
| Lot 9 — Grand livre complet | B | 2026-10-24 | 2026-10-27 (**livré**) |
| Lot 10 — Commerce I (clients, catalogue, abonnements) | A | 2026-10-24 | 2026-10-27 (**livré**) |
| Lot 11 — Commerce II (factures, encaissement) | A | 2026-10-24 | 2026-10-28 (**livré**) |
| Lot 12 — Usage et quotas | B | 2026-10-28 | 2026-11-09 |
| Lot 13 — Portail client | A | 2026-10-29 | 2026-11-16 |
| Lot 15 — Exploitation | B | 2026-11-10 | 2026-11-23 |
| *Marge de B (revue de sécurité des lots 8–11, harnais de tests IDOR)* | B | 2026-11-24 | 2026-11-27 |
| Lot 17 — Bascule (préparation, recette bout-en-bout) | A | 2026-11-17 | 2026-11-26 |
| Lot 14 — Console de portefeuille | B | 2026-11-28 | 2026-12-11 |
| Bascule, coexistence 14 j, coupure, retrait | A + exploitation | 2026-11-27 | 2026-12-29 |
| Lot 16 — Contrat et expérience développeur | B | 2026-12-14 | 2026-12-22 |
| Lot 19 — Versements sortants | B | 2026-12-23 | 2027-01-14 |
| Lot 18 — Rapprochement prestataire | B | 2027-01-15 | 2027-01-28 |

**Jalons** : socle sécurisé le 27 octobre · commerce complet (abonnements +
factures) le **28 octobre** · portail client le **16 novembre** · début de
bascule le **27 novembre** · **extinction de la plateforme transitoire le
29 décembre 2026**.

Revue d'avancement chaque vendredi ; un lot en retard de plus de 20 % sur son
estimation déclenche une replanification écrite dans cet addendum.

Fin de l'addendum — 2026-10-24
