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
| [0036](0036-paiement-confirme-credite-le-wallet-en-synchrone.md) | Un paiement confirmé crédite le wallet en appel synchrone, pas via le relais de l'outbox | Accepté — condition de déclenchement corrigée par 0037 |
| [0037](0037-credit-wallet-jamais-automatique-composition-explicite.md) | Le crédit du wallet n'est jamais automatique : composition explicite par paiement, pas comportement par défaut | Accepté — amende 0036 |
| [0038](0038-conception-lot-3-payment-channel-routage-etat-callback.md) | Conception Lot 3 Payment : channel canonique distinct du provider, routage par Project, état du paiement, deux notions de callback séparées (webhook ADR-0009 vs redirection Paylink) | Accepté |
| [0039](0039-payment-gateway-registry-typed.md) | Registre provider typé par domaine (`PaymentGatewayRegistry`) + détection des codes provider dupliqués au boot | Accepté |

### Décisions du Lot 4 (2026-09-15)

| ADR | Décision | Statut |
| --- | -------- | ------ |
| [0040](0040-conception-lot-4-relais-outbox-webhooks-sortants.md) | Conception Lot 4 : relais outbox unique et centralisé (`Core\Events\Infrastructure\Relay`), webhooks sortants signés HMAC composés directement sur `Core\Support\Integration` (pas `ExternalApiCaller`), retry persisté via Horizon `release()`, secret chiffré au repos, replay CLI en attendant le Core Admin | Accepté — précise ADR-0009 |

### Décisions du Lot 5 (2026-09-15)

| ADR | Décision | Statut |
| --- | -------- | ------ |
| [0041](0041-conception-lot-5-notification-orchestration-sans-adaptateur.md) | Conception Lot 5 : orchestration Notification livrée sans adaptateur concret — API self-service uniquement (pas d'abonnement automatique à l'outbox), fallback synchrone ordonné (pas un retry différé), `NotificationChannelProvider` sur le registre provider générique de `core/support`, `to_party_ref` comme identifiant de corrélation jamais une donnée de contact | Accepté — précise spec §9.2-§9.5 |
| [0042](0042-whatsapp-waha-adapter-channel-session-minimal-surface.md) | Adaptateur WhatsApp via WAHA auto-hébergé, surface minimale : `WahaAdapter` implémente `NotificationChannelProvider` + un nouveau `ChannelSessionProvider` (QR/pairing-code/screenshot) sur le même registre provider ; aucune persistance de session côté Core, `sessionRef` fourni par l'appelant à chaque requête | Accepté — précise ADR-0041 |
| [0043](0043-notifications-endpoints-par-type-de-contenu-sans-template.md) | Notifications : un endpoint par type de contenu (`/v1/notifications/{text,image,file}`), plus de template pré-enregistré — `notification_templates` supprimée, routage par `channel` inchangé, cascade de fallback partagée (`NotificationSendCascade`), tags OpenAPI provider en sous-groupe du module | Accepté — remplace ADR-0041 §4 |

### Décisions du Lot 6, première tranche (2026-09-15)

| ADR | Décision | Statut |
| --- | -------- | ------ |
| [0044](0044-conception-lot-6-core-admin-operateurs-rbac-audit-dlq-reconciliation.md) | Conception Lot 6 (1ère tranche) : opérateurs/RBAC en enum fixe (pas le schéma dynamique de la spec), Sanctum + MFA (`pragmarx/google2fa`), port `Audit\Contracts\AuditQuery` (corrige la dette G3c du scaffold Lot 1), DLQ webhook (liste + réutilisation intacte du replay + `QueueWebhookDelivery`), réconciliation paiement convergeant sur `PaymentStatusChangeHandler` existant | Accepté — précise spec §5.1/§8.1 |
| [0045](0045-lot-6-listing-generique-admin.md) | Conception Lot 6 (2ème tranche) : listing générique projects/payments/wallets/notifications — un port `List*` par module, réutilisation des read models existants (`PaymentView`/`NotificationView`), filtre de statut en chaîne brute dans `Contracts` (G3c), nouvelles permissions `projects:read`/`payments:read`/`wallets:read`/`notifications:read` | Accepté — suite d'ADR-0044 |
| [0046](0046-lot-6-revue-de-code-correctifs.md) | Correctifs issus de la revue de code post-Lot 6 : verrou de ligne sur la transition de statut paiement (course webhook/réconciliation), catch élargi en réconciliation, expiration des jetons opérateur, audit des actions admin, throttle login/MFA, anti-rejeu TOTP, validation UUID sur 5 endpoints, pagination déterministe, permissions RBAC mortes/incohérentes, masquage d'anomalie wallet, enums canoniques hors des DTOs `Contracts` | Accepté — précise ADR-0044/0045 |
| [0047](0047-console-web-admin-correctifs-et-mecanisme-auth.md) | Console web admin : mécanisme d'authentification par session serveur (rejette le mode stateful Sanctum, structurellement impossible), vérification de permission sur chaque page, retrait du déclenchement caché de réconciliation sur le dashboard, résolution des composants Blade, accès objet sur les DTOs `Contracts`, détail projet/paiement implémentés, négociation de contenu sur les échecs de validation, retrait de l'exclusion PHPStan globale, `tests/Feature` enfin enregistré comme testsuite | Accepté — précise ADR-0044/0045/0046 |
| [0048](0048-billing-storage-fees-developer-platform-hors-perimetre-production.md) | `billing`, `storage`, `fees`, `developer-platform` restent des scaffolds vides (aucun retrait structurel) mais sont explicitement hors périmètre de la version de production — `fees` passe de « en pause, non tranché » (ADR-0044) à « hors périmètre, tranché » | Accepté — précise ADR-0002/0044 |
| [0049](0049-documentation-api-separee-plan-operateur.md) | Documentation API séparée : « Core System API » (`/docs/api`, intégration Project/API-key) et « Core Admin API » (`/docs/admin`, plan opérateur — `v1/admin/*` + `v1/operators/*`), deux documents OpenAPI indépendants via `Scramble::registerApi()` | Accepté |
| [0050](0050-gestion-self-service-des-operateurs.md) | Gestion self-service des opérateurs (create/list/revoke) en JSON et console web, réservée à `operators:read`/`operators:write` (`PlatformAdmin` seul) ; comble le dernier non-but CLI-only d'ADR-0044 | Accepté |
| [0051](0051-remediation-securite-audit-mycoolpay-multi-tenant-ssrf-sessions.md) | Revue de sécurité complète : authentification réelle du webhook MyCoolPay (IP + signature MD5), une application MyCoolPay par Project (`payment_provider_applications`), deux endpoints de paiement + callback URL par paiement, anti-SSRF partagé (`OutboundUrlGuard`), isolation des sessions WhatsApp par Project, vérification de propriété des wallets, retrait de 3 commandes CLI sans nécessité opérationnelle, plusieurs correctifs moyens (session admin chiffrée, redaction `X-PRIVATE-KEY`, audit web console, course d'idempotence ledger, montants positifs) | Accepté |
| [0052](0052-admin-crud-organizations-projects-operateurs-applications-provider-reconciliation-wallets.md) | Console admin : création self-service Organizations/Projects, CRUD opérateur complet (ajout d'Update), enregistrement self-service des applications provider (`payment` ne peut pas dépendre d'`audit` — le contrôleur admin audite à sa place), prévisualisation de réconciliation avant déclenchement, visibilité wallet enrichie en lecture seule (ADR-0033/0037 intacts, refusé explicitement par l'utilisateur), sidebar groupée + dashboard enrichi, deux bugs de layout corrigés (sidebar vide pour tout rôle, hauteur de coquille non fixée) | Accepté |
| [0053](0053-deploiement-production-frankenphp-vps-nginx-proxy.md) | Déploiement de production : FrankenPHP en mode classique (remplace `php artisan serve`), `compose.prod.yaml` séparé de `compose.yaml`, sidecar nginx par projet pour le VPS partagé (`nginx-proxy`/`acme-companion`), Postgres/Valkey isolés sur un réseau interne jamais exposé au réseau partagé, `trustProxies` pour la chaîne de deux proxys | Accepté |
| [0054](0054-doc-scramble-completude-descriptions-exemples-enums.md) | La doc Scramble OpenAPI doit publier, pour chaque attribut de payload d'endpoint nouveau ou modifié : description (commentaire PHPDoc au-dessus de l'item `rules()`), `@example` réaliste, et `enum` borné (`Rule::in([...])` ou `Rule::enum(...)`) si le domaine est fini. Garde-fou par test d'architecture `OpenApiCompletenessTest` + `make openapi` dans `make ci`. | Accepté |
| [0055](0055-classification-http-failure-retry-policy.md) | Un échec HTTP 4xx autre que 408/429 est permanent et ne doit pas consommer le budget de retry. Helper pur `Core\Support\Http\HttpStatusClassifier`, intégré dans `WebhookSendOutcome`/`PaymentCallbackSendOutcome` (champ `isPermanentFailure`), consommé par `WebhookRetryPolicy::nextRetryAt()` (→ DLQ immédiate côté webhook) et `DeliverPaymentCallbackJob::handle()` (→ `$this->fail()` immédiat côté paiement, pas de DLQ sur ce plane par ADR-0038 §4). | Accepté |
| [0056](0056-notifications-idempotency-domain-not-http.md) | Notifications : l'idempotency quitte le middleware HTTP pour passer au domain. Champ body optionnel `client_reference` (string, max 255) ; s'il est fourni, il sert de clé d'idempotency, sinon le Core génère un UUID. Le middleware `idempotency` est retiré des 3 routes notification ; il reste obligatoire sur les 3 routes payment (le coût direct d'un double débit justifie le HTTP-level cache + 409 detection, ADR-0025). | Accepté |
| [0057](0057-whatsapp-session-du-client-obligatoire.md) | Sessions WhatsApp : créées par l'admin (jamais par le client), enregistrées dans une table du Core (liées à un projet ou globales), `variables.session` obligatoire et vérifiée dans cette table à l'envoi ; supprime le repli `WAHA_DEFAULT_SESSION` | Accepté — remplace ADR-0042 §4 |
| [0058](0058-correctifs-audit-2026-10-03-ssrf-proxies-webhooks.md) | Correctifs de l'audit du 2026-10-03 : aucune redirection suivie, `GuardedHttpTransport` (re-vérification + épinglage IP à la connexion), plages IP supplémentaires bloquées, HTTPS obligatoire pour webhooks/callbacks, `trustedproxy.proxies` (IP client réelle derrière deux proxies), plafond API par IP, statut `canceled` + `failed()` + `webhook:sweep` + rétention, files Horizon dédiées | Accepté |
| [0059](0059-observabilite-stack-centralisee-et-parcours-metier.md) | Observabilité : `correlation_id` + `trace_id` propagés jusqu'aux jobs (via l'outbox), logs HTTP requête/réponse rédigés, traces OTLP, `/metrics` + `/ready`, stack auto-hébergée Alloy/Loki/Tempo/Prometheus/Grafana provisionnée en code (tableaux de bord + alertes), « Parcours » métier de chaque paiement (`payment_timeline_entries`) et historique complet des tentatives webhook (`webhook_delivery_attempts`) | Accepté |
| [0060](0060-environnements-isoles-cles-api-et-applications-provider.md) | Sandbox et live isolés (colonne `environment` sur paiements, notifications, endpoints, outbox, applications provider) ; une seule clé API par environnement (générer / régénérer atomiquement / révoquer = supprimer) ; applications provider par environnement avec édition et messages clairs (fin du « duplicate key ») ; simulateur sandbox intégré + `POST /v1/sandbox/payments/{id}/simulate` + page de paiement sandbox hébergée ; aucun mouvement ledger en sandbox | Accepté |
| [0061](0061-statuts-finals-et-contrat-api-minimal.md) | Statuts intermédiaires (`pending`, `processing`) vs finaux, machine d'états (transitions arrière refusées et tracées) ; payload `payment.status_changed` unique `{id, type, data}` pour webhooks et `callback_url` (`payment_id`, `reference`, `status`, `is_final`, `amount`, `currency`, `failure_reason`, `occurred_at` — plus de `provider_code`) lié au schéma OpenAPI par un test de contrat ; réponses de paiement réduites au strict nécessaire | Accepté — amende ADR-0035/0038 |
| [0062](0062-console-admin-donnees-completes.md) | Console admin : toutes les données dans les fiches (paiement, notification avec contenu/variables/tentatives, livraison webhook) ; environnement visible et filtrable partout ; contenu des notifications conservé 90 jours puis vidé (`notification:redact-expired`) | Accepté — complète ADR-0045/0059 |
