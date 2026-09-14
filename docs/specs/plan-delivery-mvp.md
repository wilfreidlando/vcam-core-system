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
