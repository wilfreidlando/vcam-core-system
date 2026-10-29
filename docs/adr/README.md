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
| [0028](0028-versionnement-api-par-url.md) | Versionnement d'API par préfixe d'URL | Accepté — fenêtre de dépréciation proposée par ADR-0086 |

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
| [0033](0033-conception-lot-2-money-core.md) | Conception Lot 2 : pas d'endpoint HTTP de mutation wallet, idempotence à deux niveaux, chart of accounts extensible | Accepté — amende 0025 ; §1 remplacé par ADR-0065 |
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
| [0048](0048-billing-storage-fees-developer-platform-hors-perimetre-production.md) | `billing`, `storage`, `fees`, `developer-platform` restent des scaffolds vides (aucun retrait structurel) mais sont explicitement hors périmètre de la version de production — `fees` passe de « en pause, non tranché » (ADR-0044) à « hors périmètre, tranché » | Accepté — précise ADR-0002/0044, amendé par ADR-0068 |
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
| [0060](0060-environnements-isoles-cles-api-et-applications-provider.md) | Sandbox et live isolés (colonne `environment` sur paiements, notifications, endpoints, outbox, applications provider) ; une seule clé API par environnement (générer / régénérer atomiquement / révoquer = supprimer) ; applications provider par environnement avec édition et messages clairs (fin du « duplicate key ») ; simulateur sandbox intégré + `POST /v1/sandbox/payments/{id}/simulate` + page de paiement sandbox hébergée ; aucun mouvement ledger en sandbox | Remplacé par ADR-0066 |
| [0061](0061-statuts-finals-et-contrat-api-minimal.md) | Statuts intermédiaires (`pending`, `processing`) vs finaux, machine d'états (transitions arrière refusées et tracées) ; payload `payment.status_changed` unique `{id, type, data}` pour webhooks et `callback_url` (`payment_id`, `reference`, `status`, `is_final`, `amount`, `currency`, `failure_reason`, `occurred_at` — plus de `provider_code`) lié au schéma OpenAPI par un test de contrat ; réponses de paiement réduites au strict nécessaire | Accepté — amende ADR-0035/0038 |
| [0062](0062-console-admin-donnees-completes.md) | Console admin : toutes les données dans les fiches (paiement, notification avec contenu/variables/tentatives, livraison webhook) ; environnement visible et filtrable partout ; contenu des notifications conservé 90 jours puis vidé (`notification:redact-expired`) | Accepté — complète ADR-0045/0059 |
| [0063](0063-documentation-integrateur-testable-et-test-de-webhook.md) | `POST /v1/webhook-endpoints/{id}/test` (événement `webhook.test` signé, synchrone) ; catalogue d'événements unique `WebhookEventTypes` (retrait de `notification.status_changed` jamais émis, ajout de `notification.delivered`/`read`) ; `docs/integration/webhooks.md` avec vérificateurs PHP/Node/Python exécutés par les tests ; guide curl réécrit | Accepté — complète ADR-0040/0061 |
| [0064](0064-infrastructure-vps-staging-observabilite-mutualisee.md) | Standard d'hébergement du VPS (dépôt [vcam-infra-deploy](https://github.com/wilfreidlando/vcam-infra-deploy), ex-`infra/`) : 12 règles + audit en lecture seule ; déploiement par promotion de l'image validée en staging (staging auto, prod manuelle, sauvegarde avant migration, retour arrière automatique) ; staging Core isolé ; observabilité mutualisée opt-in par labels ; sauvegardes chiffrées vers S3 (MEGA S4) ; proxys de confiance limités au sidecar (jeton) | Accepté — amende ADR-0053/0059 |
| [0065](0065-operations-wallet-exposees.md) | Opérations de wallet exposées : transferts multi-jambes atomiques et à somme nulle, sorties enregistrées, historique et wallets par partie (`wallets:write`/`wallets:read`) ; aucun crédit libre | Accepté — remplace ADR-0033 §1 |
| [0066](0066-un-seul-environnement-staging-pour-les-tests.md) | Un seul environnement : plus de sandbox/live (colonnes, clés, `Core\Support\Environment`), une clé par projet, une application provider par (projet, provider) ; tests sur le déploiement de staging, simulateur de paiement activé par `PAYMENT_SIMULATOR_ENABLED` (`POST /v1/payments/{id}/simulate`) ; données sandbox supprimées | Accepté — remplace ADR-0060 |
| [0067](0067-reconciliation-rapprochee-et-acces-horizon.md) | Réconciliation des paiements chaque minute, seuil 2 minutes, à tour de rôle (`payments.reconciled_at`, `GREATEST(updated_at, reconciled_at)`) pour qu'aucun paiement abandonné n'en bloque d'autres, fenêtre de 24 heures ; `/horizon` ouvert aux opérateurs `PlatformAdmin` connectés à la console (permission `queues:manage`) | Accepté — amende ADR-0044 §7 |

### Plateforme multi-tenant de projets SaaS (2026-10-24)

Cible : une plateforme qui gère des projets SaaS de bout en bout, pour toute
organisation (l'entreprise ou une organisation tierce), en trois piliers —
services de plateforme, grand livre, commerce SaaS (ADR-0068). Tous **acceptés**
par le comité d'architecture le 2026-10-27. Sept d'entre eux (0069, 0076, 0077,
0078, 0081, 0082, 0083) avaient déjà leur code livré et conforme au moment de la
décision : le code a précédé la ratification, il ne l'attendait pas — le comité a
ratifié ce que le lot 8 avait produit, après avoir vérifié la conformité plutôt
que de l'imposer a posteriori. Ils ne nomment aucun produit ; la correspondance
avec les dépôts voisins est dans les documents de transition de `docs/specs/`
(`*-fusion.md`).

| ADR | Décision | Statut |
| --- | -------- | ------ |
| [0068](0068-cible-plateforme-saas-trois-piliers.md) | Cible en trois piliers (services, grand livre, commerce SaaS), vocabulaire fixé, graphe de modules cible (`portal`, `payout`, `notification → billing`) | Accepté — amende ADR-0048 |
| [0069](0069-organisations-projets-et-acces-console-scopes.md) | Appartenances d'organisation et rôles (`org_owner` … `org_viewer`), `AccessScope` obligatoire dans tout port appelé par la console (garde-fou G16), cycle de vie d'un projet (`active`/`suspended`/`archived`), invitation par jeton d'activation | Accepté — amende ADR-0044/0050/0052 |
| [0070](0070-clients-d-un-projet.md) | Clients d'un projet dans `billing` : identité minimale chiffrée, index aveugles, upsert idempotent `PUT /v1/customers/{external_ref}`, événements sans donnée personnelle | Accepté — amende gouvernance §6 |
| [0071](0071-catalogue-plans-versionnes-et-fonctionnalites.md) | Plans stables + versions immuables (prix, devise, intervalle, essai, grâce, fonctionnalités `boolean`/`limit` nommées par le projet), arithmétique des périodes dans le fuseau du projet | Accepté |
| [0072](0072-abonnements-contrat-periodes-et-machine-d-etats.md) | Abonnement = contrat + périodes sans chevauchement (contrainte d'exclusion), machine d'états à 7 statuts, renouvellement par période suivante pré-créée, changement de plan, gestes d'exploitation audités, planificateur sur `Clock` | Accepté |
| [0073](0073-factures-encaissement-et-paiements-hors-ligne.md) | Factures immuables à l'émission, numérotation sans trou, tentatives de paiement rattachées par `payment_id`, imputation au grand livre, encaissement hors ligne à quatre yeux, documents HTML/PDF | Accepté — amende ADR-0037 |
| [0074](0074-usage-et-quotas.md) | Journal d'usage append-only + compteur atomique dans la transaction de l'effet, idempotence par clé, `POST /v1/usage`, port `QuotaGuard` de `notification`, attribution obligatoire quand la facturation est active | Accepté — modifie le graphe |
| [0075](0075-portail-client-heberge.md) | Portail client hébergé : sessions créées par le serveur du projet (portée `portal:write`, lien à usage unique), connexion directe par code optionnelle, personnalisation, liste blanche des URL de retour | Accepté — nouveau module `portal` |
| [0076](0076-grand-livre-complet-plan-de-comptes.md) | Toute somme est écrite : plan de comptes par projet (`clearing:<prestataire>`, `collections`, `customer_receivable`, `revenue`…), `purpose` du paiement, lectures par période et balance, trigger d'isolation inter-projets | Accepté — amende ADR-0037/0033 |
| [0077](0077-confirmation-des-encaissements-et-exceptions.md) | Montant confirmé par le prestataire vérifié ; écart → compte `payment_exceptions`, jamais imputé ; file d'exceptions d'argent unique, résolution à quatre yeux | Accepté — amende ADR-0038/0051 |
| [0078](0078-rapprochement-et-controles-d-invariants.md) | Trois niveaux : invariants internes (horaire), rapprochement transactionnel et de solde par capacités d'adaptateur ; un rapprochement constate, il n'écrit jamais | Accepté — complète ADR-0067 |
| [0079](0079-versements-sortants.md) | Module `payout` : écriture avant appel (`payout_in_transit`), trois voies de confirmation, résultat ambigu laissé `pending`, compte du projet seulement, plafonds et double validation | Accepté — nouveau module |
| [0080](0080-pilotage-des-projets-saas.md) | Console de portefeuille : indicateurs définis (MRR par devise, attrition, encaissé et créances lus dans le grand livre), instantanés quotidiens, liste de contrôle d'onboarding | Accepté — amende ADR-0045/0062 |
| [0081](0081-modele-de-menace-et-exigences-de-securite.md) | Modèle de menace, exigences S-01 à S-20, nouveaux garde-fous G13 (isolation financière), G14 (aucun secret en réponse hors création), G15 (tests IDOR générés) | Accepté |
| [0082](0082-cles-d-api-portees-rotation-et-secrets-de-webhook.md) | Plusieurs clés nommées par projet, portées choisies, rotation guidée et révocation d'urgence, restriction réseau, double signature des webhooks pendant la rotation | Accepté — amende ADR-0066/0044 |
| [0083](0083-donnees-personnelles-chiffrement-conservation-effacement.md) | Minimisation, chiffrement applicatif à clé dédiée et versionnée, index aveugles, audit sans valeurs personnelles, conservation paramétrée, effacement sans toucher au grand livre, export par client | Accepté |
| [0084](0084-contrat-d-integration-d-un-projet.md) | Le projet possède son domaine et ses utilisateurs, le core le commerce et l'argent ; droits lus + webhooks versionnés ; cloisonnement fail-closed exigé ; pas de fournisseur d'identité pour les utilisateurs finaux | Accepté |
| [0085](0085-limitation-de-debit-observabilite-et-objectifs-de-service.md) | Débit par IP, projet, clé et classe de route ; métriques par projet sans donnée personnelle ; objectifs de service et alertes par budget d'erreur | Accepté |
| [0086](0086-contrat-public-versionnement-depreciation.md) | Majeure dans l'URL, ajouts libres, énumérations ouvertes déclarées, 12 mois de vie après la majeure suivante, `Deprecation`/`Sunset`/`410`, `api_version` dans les événements, G9 étendu au plan d'administration | Accepté — complète ADR-0028 |
| [0087](0087-catalogue-de-capacites-et-experience-developpeur.md) | Registre de capacités vérifié contre l'OpenAPI, page de démarrage testée par capacité, horloges de test par projet sur le staging | Accepté — amende ADR-0048/0066 |
| [0088](0088-reversibilite-listings-et-export.md) | Listings publics par curseur pour tous les objets, export asynchrone par projet au format du contrat public, parcours de sortie | Accepté |
| [0089](0089-absorption-de-la-plateforme-transitoire.md) | Correspondance et sort de chaque brique transitoire, flux JWT retiré, synchronisation inversée, bascule à blanc pilotée par la recette, extinction en cinq temps | Accepté |
| [0090](0090-evolutivite-et-points-d-extension.md) | Partitionnement, relais de l'outbox réveillé après commit, équité des files, configuration par projet en base ; registre des points d'extension (prestataires, types de fonctionnalité, lignes de facture, prorata, prélèvement, domaine du portail, OAuth) | Accepté |
| [0091](0091-decisions-implicites-du-code-livre-formalisees.md) | Huit contrats publics introduits par le code sans ADR, désormais écrits : résolution ou création d'un compte (`AccountResolver`), devise supportée (`IsSupportedCurrency`), mise à jour et lecture d'organisation/projet, cycle de vie d'un endpoint de webhook | Accepté — amendé ADR-0040/0052/0065 |
| [0092](0092-lot-10-lAbonnement-et-la-facture-au-lot-11.md) | Lot 10 : la facture appartient au lot 11 — une période existe avant sa facture, le fait de paiement est un champ (`paid_at`) qui ne déplace aucun argent, les actes d'exploitation sont audités par le contrôleur de console, `billing_settings` tient quatre réglages, les fonctionnalités sont validées par format, `customers` a une liste blanche de colonnes (G4b), la plane publique déclare ses corps dans des `FormRequest`, la console de la facturation est portée par le projet (404 pour une lecture, redirection « introuvable » pour un geste) | Accepté — précise ADR-0070/0071/0072/0073 |
| [0093](0093-lot-10-revue-de-code-corrections.md) | Lot 10, revue de code : la pause est permise depuis tout état non final, une passe de l'ordonnanceur isole l'échec d'un contrat, seule la période courante et facturée peut être déclarée réglée, l'effacement est final pour l'upsert, une version de plan retirée le reste, la console n'affiche jamais une donnée personnelle en clair, une clé d'idempotence est libérée quand la requête échoue, le verrou optimiste du client est dans l'écriture | Accepté — amends ADR-0025/0070/0071/0072/0083 |
| [0094](0094-lot-11-factures-et-encaissement.md) | Lot 11 : la facture est un document figé à l'émission, la taxe est stockée par ligne, le numéro est attribué sous verrou sans trou, l'émission inscrit la créance au grand livre et l'annulation la contre-passe, le snapshot du client est chiffré, le `purpose` du paiement existe enfin | Accepté — amendé par ADR-0072/0073/0076/0083/0092/0095 |
| [0095](0095-encaissement-hors-ligne-validation-a-un-compte.md) | L'encaissement hors ligne se valide à un compte : la contrainte `manual_receipts_four_eyes` tombe, l'auto-validation est permise ; le seuil garde son rôle — au-dessus, le reçu attend un acte de validation explicite et audité avant de compter | Accepté — amends ADR-0073/0081/0094 |
| [0096](0096-developer-platform-reste-un-scaffold-jusqu-au-lot-16.md) | `developer-platform` reste un scaffold jusqu'au lot 16 : l'entrée en production décidée par ADR-0068 et ADR-0087 est différée, le module n'a jamais été construit et aucun texte ne doit dire le contraire | Accepté — amends ADR-0048/0068/0087 |
| [0097](0097-portail-un-appel-assets-sans-build-paiement-sans-configuration.md) | Le portail se branche en un appel : `customer` et `plan_code` dans `POST /v1/portal/sessions`, paiement sans configuration (canaux routés par la plateforme), assets sans build sous CSP stricte, événements de commerce abonnables, kit Laravel | Accepté — réalise ADR-0075/0084 |
| [0098](0098-libre-service-du-portail-et-ecarts-de-livraison.md) | Libre-service du portail (souscrire, renouveler, reprendre le renouvellement), profil en lecture seule, retrait de la pause libre-service et de l'ajustement d'usage, écart de partition assumé | Proposé — amende ADR-0075 §3, 0072 §9, 0074 §1, 0090 §1 |
| [0099](0099-fees-storage-et-extensions-commerce-refuses.md) | `fees`, `storage` et `developer-platform` non nécessaires pour le moment (liste négative) ; relances, codes promo, e-mail/SMS renvoyés à une demande réelle | Proposé — amende ADR-0048, 0068 |
| [0100](0100-portail-connexion-par-code-arete-notification.md) | Connexion directe du portail par code (ADR-0075 §2) : arête `portal → notification`, code HMAC 6 chiffres / 10 min / 5 essais, réponse identique contact connu ou non, limites fermées en cas de panne, `FindCustomerByContact` de `billing` | Proposé — réalise et amende ADR-0075 §2, 0068 |
| [0101](0101-export-par-projet-module-et-aretes.md) | Export par projet (ADR-0088 §3) : module `export` (hub de lecture, personne ne dépend de lui), arêtes `export → identity, audit, ledger, billing, payment, notification, wallet` par `Contracts/`, format de l'API publique réutilisé, archive `.zip` JSON Lines chiffrée par blocs, lien signé 15 min, purge à 7 jours | Proposé — réalise et amende ADR-0088 §3, 0068 |
