# ADR-0051 — Revue de sécurité complète et correctifs : authenticité webhook MyCoolPay, applications multi-tenant, anti-SSRF, isolation des sessions WhatsApp, propriété des wallets

- **Statut** : Accepté
- **Date** : 2026-09-20
- **Décideurs** : décision de l'utilisateur, suite à une revue de sécurité multi-agents demandée explicitement
- **Dérives concernées** : aucune (correctifs de sécurité, pas de nouvelle capacité métier hors périmètre)

## Contexte

L'utilisateur a demandé une revue de sécurité complète du dépôt (le pipeline `claude-security` automatisé s'est révélé indisponible dans cette session — absence de l'outil `Workflow` — donc la revue a été menée manuellement par 5 agents parallèles en lecture seule, chacun couvrant un périmètre : ledger/wallet, paiement/webhook, identity/MFA, admin/RBAC + Support Integration, notification/WAHA + parties). Les constats les plus graves ont ensuite été corrigés avec l'accord explicite de l'utilisateur ("pour les points élevés corrige"), avec plusieurs demandes d'extension de périmètre formulées en cours de route (applications MyCoolPay par Project, deux endpoints de paiement, callback URL par paiement).

## Constats et décisions, par gravité

### 1. CRITIQUE — Webhook entrant MyCoolPay sans authenticité réelle

**Constat** : `MyCoolPayWebhookController` ne vérifiait que le champ `application` (la clé publique) contre la configuration — or MyCoolPay documente explicitement que cette clé n'est pas secrète (visible dans chaque lien de paiement montré au client). N'importe qui ayant vu un lien de paiement pouvait forger une requête faisant passer un paiement à `Succeeded`, déclenchant un crédit wallet synchrone (ADR-0036/0037).

**Décision** : implémenter la procédure officielle documentée par MyCoolPay (API docs §7.5 "Callback requests integrity", retrouvée via leur documentation Postman publique) :
1. Vérification de l'IP source (`15.236.140.89` à ce jour, configurable via `MYCOOLPAY_CALLBACK_TRUSTED_IPS`, liste vide = permissif pour sandbox/local).
2. Vérification de la signature MD5 : `md5(transaction_ref + transaction_type + transaction_amount + transaction_currency + transaction_operator + private_key)`.

Les deux checks sont faits **avant** tout traitement métier, par `MyCoolPayCallbackAuthenticator` (`Core\Payment\Infrastructure\Provider\MyCoolPay`), exposé via le port `Domain\Port\PaymentCallbackAuthenticator` (Presentation ne peut pas dépendre d'Infrastructure directement — deptrac-layers.yaml). Un échec renvoie **403**, pas 200 — signal net dans les logs/métriques, sans risque puisque MyCoolPay ne rejoue jamais un callback.

### 2. Extension du périmètre — une application MyCoolPay par Project

**Constat de l'utilisateur** (message explicite, en cours de correctif du point 1) : MyCoolPay permet de créer plusieurs "applications" (une paire clé publique/clé privée par application), et le Core doit en tenir compte — un seul callback URL partagé (le nôtre) reçoit les notifications de toutes les applications, à charge pour le Core d'attribuer chaque callback au bon Project.

**Constat technique** : avant ce tour, `MyCoolPayAdapter` était construit une seule fois avec **une seule** paire de clés statique (`config/providers.php`). Tous les Projects partageaient la même application MyCoolPay — en plus d'être incorrect fonctionnellement, cela aggravait le point 1 (une seule clé privée à connaître pour forger n'importe quel paiement).

**Décision** (option choisie explicitement par l'utilisateur parmi 3 proposées) : une table dédiée liée au Project, chiffrée comme le secret HMAC des webhooks.
- `payment_provider_applications` (`project_id`, `provider_code`, `public_key`, `private_key_encrypted`) — unique sur `(project_id, provider_code)` et sur `(provider_code, public_key)` (ce dernier est la clé de résolution côté callback entrant). Pas de FK vers `projects` (ADR-0033 §5).
- `Domain\Model\ProviderApplication` + `Domain\Port\ProviderApplicationRepository` + `Infrastructure\Persistence\Eloquent\EloquentProviderApplicationRepository` (chiffrement `Crypt`, même pattern que `webhook_endpoints.secret_encrypted`).
- Bootstrap CLI-only, jamais self-service (mirroir `identity:issue-api-key`) : `payment:register-provider-application {project} {provider} {public-key} {private-key}` — pas de flux d'onboarding self-service pour une nouvelle application provider aujourd'hui.
- `MyCoolPayAdapter::initiate()/submitAction()/checkStatus()` résolvent désormais la clé publique du Project via ce repository — `ProviderApplicationNotFound` sans fallback vers une clé partagée si aucune application n'est enregistrée.
- `InitiatePaymentCommand`, `PaymentAction` et `PaymentGateway::checkStatus()` portent désormais `projectId` (changement d'interface — le seul appelant de `submitAction`/`PaymentAction` en production n'existait pas encore, donc aucune rupture réelle).

### 3. Extension du périmètre — deux endpoints de paiement, callback URL par paiement

**Constat de l'utilisateur** : demande explicite de deux endpoints distincts (`POST /v1/payments/paylink` et `POST /v1/payments/payin`) plutôt qu'un seul générique avec un champ `flow` — anticipant qu'un futur second provider de paiement pourra réexploiter la même API sans changement pour l'appelant. Puis, en cours de discussion, demande d'un `callback_url` par paiement : `return_url` (redirection navigateur) peut ne jamais se déclencher (onglet fermé, connexion perdue), donc l'app métier a besoin d'un canal fiable serveur-à-serveur pour savoir qu'un paiement est terminé — y compris pour le flow `payin` qui n'a même pas de redirection.

**Décision** :
- `AbstractStartPaymentController` porte la logique commune ; `StartPaymentController` (`/v1/payments`, conservé tel quel pour la rétrocompatibilité — G9, retirer une route publiée est une rupture), `StartPaylinkPaymentController` (`/v1/payments/paylink`, flow forcé) et `StartPayinPaymentController` (`/v1/payments/payin`, flow forcé) en héritent. Chaque sous-classe **surcharge** `__invoke()` avec son propre docblock (Scramble documente par méthode réellement présente sur la route, pas par héritage — sinon les trois routes auraient partagé la même description).
- `callback_url` (optionnel, sur les trois endpoints), validé anti-SSRF (voir point 4). Un secret HMAC est généré (`PaymentCallbackSecretGenerator`, même format `random_bytes()` que les autres secrets du Core) et retourné **une seule fois** dans la réponse d'initiation (`callback_secret`), jamais réexposé sur replay idempotent ni sur les lectures ultérieures (`PaymentView::$callbackSecret` documenté comme n'étant peuplé que par `StartPaymentUseCase`).
- Livraison : `HttpPaymentCallbackSender` (implémente `Domain\Port\PaymentCallbackSender`) compose les mêmes primitives que `Core\Webhook\Infrastructure\Delivery\HttpWebhookSender` (`HttpTransport` + `RequestSigner`/`SignaturePolicy`, seul point de sortie HTTP — G6c) mais **n'est délibérément pas** le mécanisme `webhook` existant : celui-ci diffuse vers des `WebhookEndpoint` enregistrés par Project pour un type d'événement, alors qu'un callback de paiement est une destination ponctuelle fournie à l'initiation, propre à un seul paiement.
- Déclenchement : `DeliverPaymentCallbackListener` (`ShouldQueue`), enregistré comme `FanOutRelayedEventListener` sur `OutboxEventPublished`, filtre sur `payment.status_changed`, résout le `Payment` par `(providerCode, transactionRef)` (le payload de l'événement canonique ne porte pas d'id de paiement — ADR-0038 §4/§5) et délègue à `DeliverPaymentCallbackJob`. **Aucune modification** de `HandlePaymentStatusChangedUseCase` : l'Application layer ne dispatche jamais directement un Job (G8, PHPat `EventsOnlyViaOutbox` — seul l'outbox déclenche des effets de bord).
- Portée volontairement limitée (documentée, pas un oubli) : pas de DLQ/rejeu persistant pour cette livraison — `$tries`/`backoff()` du job Laravel seulement. `GET /v1/payments/{id}` reste la source de vérité si la livraison est définitivement épuisée ; un DLQ dédié pour les callbacks de paiement est un chantier futur distinct, hors de ce qui a été demandé ici.

### 4. ÉLEVÉ — Aucune protection anti-SSRF sur les URLs fournies par l'appelant

**Constat** : `POST /v1/webhook-endpoints` (`url`) n'avait aucune validation — un Project pouvait enregistrer une cible interne (`169.254.169.254`, `10.0.0.0/8`, `localhost`) ; risque persistant (retries + DLQ replay).

**Décision** : `Core\Support\Integration\Security\OutboundUrlGuard` (résolution DNS injectable pour rester hermétique en test, `filter_var(..., FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)` sur chaque adresse résolue — couvre nativement privé/loopback/link-local/réservé, y compris l'adresse de métadonnées cloud) + `SafeOutboundUrlRule` (règle de validation Laravel réutilisable). Les deux vivent dans `core/support` (G3d respecté — aucune persistance) car utilisés par deux modules (`webhook` et `payment` — barre ADR-0020 des ≥2 modules déjà franchie). Risque résiduel documenté explicitement : c'est une vérification au moment de la validation, pas une garantie au moment de la connexion — un DNS-rebinding réellement adversarial n'est pas couvert (ni `LaravelHttpTransport` ni le chemin de livraison `webhook` n'épinglent l'IP résolue jusqu'à la connexion réelle).

### 5. ÉLEVÉ — Détournement de session WhatsApp inter-Project

**Constat** : les endpoints de gestion de session WAHA (`GET .../qr`, `GET .../screenshot`, `POST .../pairing-code`) et l'envoi de message (`variables['session']`) transmettaient `sessionRef` au provider tel quel — `projectId` ne servait qu'à résoudre le provider, jamais à vérifier la propriété de la session. Un Project A connaissant/devinant le nom de session d'un Project B pouvait récupérer son QR code, sa capture d'écran, voire relier son propre numéro à la session de B, ou envoyer des messages en usurpant son identité WhatsApp.

**Décision** : `Core\Notification\Domain\Model\ScopedSessionRef` — namespacing déterministe `{projectId}:{sessionRef}`, sans aucune persistance nouvelle (ADR-0042 "le Core ne persiste rien sur une session" reste vrai). Appliqué :
- Dans les trois use cases de gestion de session (`GetChannelSessionQrCodeUseCase`, `GetChannelSessionScreenshotUseCase`, `RequestChannelSessionPairingCodeUseCase`) avant tout appel au provider.
- Dans `WahaAdapter::send()`, uniquement quand `variables['session']` est fourni par l'appelant — le fallback `extras.default_session` (config, jamais saisi par un appelant) reste **non scopé** : c'est une ressource plateforme délibérément partagée, pas une entrée utilisateur, donc rien à détourner. `OutboundMessage` porte désormais `projectId`.

### 6. ÉLEVÉ — Wallet crédité/débité sans vérification de propriété

**Constat** : `CreditWalletUseCase`/`DebitWalletUseCase` faisaient confiance à `(projectId, partyId)`/`walletId` fournis par l'appelant sans vérifier qu'ils correspondaient réellement au même Project. Non exploitable via HTTP public aujourd'hui (Wallet n'expose aucun endpoint de mutation — ADR-0033 §1), mais l'unique appelant en pratique était l'outil CLI `wallet:credit`/`wallet:debit`, sans aucun garde-fou.

**Décision** :
- `Domain\Exception\WalletProjectMismatch` levée par `CreditWalletUseCase` si le wallet existant pour `(partyId, currency)` appartient à un autre Project, et par `DebitWalletUseCase` si le wallet résolu par id n'appartient pas au `projectId` déclaré. `DebitWalletCommand` porte désormais un `projectId` obligatoire (il n'en portait aucun avant).
- CLI `wallet:credit`/`wallet:debit` **supprimées** (voir point 7) plutôt que corrigées seules : aucun besoin opérationnel réel identifié (le crédit automatique passe par `HandlePaymentStatusChangedUseCase`), et l'outil ajoutait une surface non gardée pour un gain nul.

### 7. Décision de l'utilisateur — retrait des CLI de fonctionnalité métier sans nécessité opérationnelle

**Instruction explicite** : « je ne veux plus de cli pour les fonctionnalités métier sauf si nécessaire pour le fonctionnement, sinon supprime. »

**Décision, par commande** :
- `wallet:credit` / `wallet:debit` (`packages/wallet`) — **supprimées**. Le crédit automatique du Lot 3 ne passe pas par ces commandes ; aucun besoin d'ajustement manuel identifié à ce jour. `WalletConcurrencyTest` (test de concurrence réelle par sous-processus OS) dépendait de `wallet:debit` — remplacé par `DebitWalletForConcurrencyTestingCommand`, une commande **test-only**, enregistrée uniquement quand `app()->environment('testing')` (jamais `local`, jamais production), qui ne porte aucun des risques de l'ancien outil.
- `admin:current-otp` (`packages/identity`) — **supprimée**. Outil de confort dev (afficher le TOTP courant d'un opérateur sans lire les logs), explicitement marqué "Dev-only — do not ship in production" dans sa propre description. Le bypass `IDENTITY_DISABLE_TOTP_IN_LOCAL` livré plus tôt dans cette session couvre déjà le besoin qu'il résolvait ; le laisser en place représentait un risque de contournement MFA (accès CLI = lecture du code TOTP courant de n'importe quel opérateur) sans bénéfice net.
- **Conservées** (nécessaires au fonctionnement, aucune alternative self-service) : `identity:create-organization`, `identity:create-project`, `identity:issue-api-key`, `identity:revoke-api-key`, `identity:create-operator` (bootstrap du tout premier `PlatformAdmin`), `admin:reconcile-stuck-payments` / `events:relay` (automatisation planifiée, pas un raccourci métier), `webhook:replay` (fallback CLI existant, converge sur le port `Contracts` déjà revu et sûr).

### 8. MOYEN — Corrections diverses

- **`.env.example` livrait `IDENTITY_DISABLE_TOTP_IN_LOCAL=true` par défaut** (erreur introduite dans cette même session) — corrigé à `false`, alignné sur le défaut sûr de `config/identity.php`. Un `cp .env.example .env` sans y toucher n'active plus le bypass.
- **Session admin non chiffrée par défaut** (`SESSION_ENCRYPT=false`, défaut Laravel) alors qu'elle porte un bearer token Sanctum pleinement authentifié (ADR-0047) — défaut applicatif changé à `true` dans `config/session.php` et `.env.example`. Un accès en lecture à Redis/Valkey ne suffit plus à voler un token de session déjà authentifié.
- **Header `X-PRIVATE-KEY` (MyCoolPay) absent de la liste de redaction** (`SensitiveKeySet::HEADER_NAMES`) — ajouté. Fuite possible en clair dans les logs si `MYCOOLPAY_DEBUG` était activé ; ce flag lui-même reste sans double-verrou environnement (contrairement au bypass TOTP), jugé hors périmètre de ce tour puisque la redaction ferme déjà la fuite concrète.
- **Code MFA de l'API JSON sans limite de longueur** (`OperatorMfaCodeRequest`) — alignée sur le formulaire web (`min:6|max:8`).
- **Audit manquant sur le rejeu de webhook DLQ et le déclenchement de réconciliation depuis la console web** — l'audit était câblé dans les contrôleurs JSON (`packages/admin`) mais absent des contrôleurs web équivalents (`app/Http/Controllers/Admin`). Centraliser dans les use cases `webhook`/`payment` aurait nécessité une nouvelle arête de dépendance vers `audit` dans `tests/Architecture/ModuleGraph.php` (`webhook`/`payment` ne l'ont pas aujourd'hui) — changement de graphe hors périmètre d'un correctif de revue (CLAUDE.md : « Modifier ce graphe = ADR + comité d'architecture »). Correctif retenu : dupliquer l'appel d'audit directement dans les deux contrôleurs web, à l'identique de leurs équivalents JSON — `app/` n'est pas un module au sens deptrac, aucune règle ne l'interdit.
- **Course de concurrence sur `PostLedgerTransaction::post()`** — deux appels `post()` concurrents avec la même clé d'idempotence pouvaient tous deux passer le contrôle `findByIdempotencyKey()` (aucun n'a encore commité, READ COMMITTED), puis l'un des deux essuyait une `UniqueConstraintViolationException` non interceptée (500) au lieu du résultat idempotent attendu — aucun double-post réel (la contrainte unique protège déjà ça), seulement une mauvaise réponse. `EloquentLedgerTransactionRepository::save()` enveloppé dans une SAVEPOINT (même pattern que `EloquentWalletRepository`/`EloquentPaymentRepository`), lève désormais `Domain\Exception\LedgerTransactionAlreadyExists` ; `PostLedgerTransaction::post()` la rattrape et relit le gagnant.
- **Aucune validation applicative du montant positif** — reposait entièrement sur la contrainte `CHECK (amount_minor > 0)` de PostgreSQL, qui rejette correctement mais remonte comme une `QueryException` non gérée depuis l'intérieur d'une transaction. `LedgerTransaction` (Domain) vérifie désormais chaque entrée via `Money::isPositive()` avant tout accès base, levant `Domain\Exception\NonPositiveLedgerEntryAmount`.

### Non-buts explicites de ce tour

- Vocabulaire de redaction carte bancaire (`card`/`pan`/`cvv`) — aucun adaptateur carte n'existe encore ; l'utilisateur a confirmé « pour le moment on a un seul provider », donc reporté sans risque actuel.
- Secret webhook WAHA statique sans nonce/horodatage — impact jugé faible (traitement idempotent, rejeu inerte), non traité.
- `MYCOOLPAY_DEBUG` sans double-verrou environnement — la redaction ferme la fuite concrète (point 8) ; un verrou dédié resterait une amélioration de défense en profondeur pour un tour ultérieur.
- DLQ/rejeu persistant pour les callbacks de paiement (point 3) — portée volontairement réduite, `GET /v1/payments/{id}` reste le filet de sécurité.

## Vérification

Après chaque correctif, sur l'ensemble du dépôt : `vendor/bin/pint --test`, `vendor/bin/phpstan analyse` (niveau 10, aucune exclusion), `vendor/bin/deptrac analyse` sur les deux graphes (modules + couches, 0 violation), `./tools/guards/verify-guards.sh` (14/14), `php artisan test --parallel` (523 tests). Vérification empirique réelle en plus des tests automatisés : appels HTTP réels contre le webhook MyCoolPay (IP/signature valides et invalides), flux complet de paiement `paylink`/`payin` avec `Http::fake()` côté MyCoolPay, régénération et lint (`spectral`) des deux documents OpenAPI (`openapi/v1.json`, `openapi/admin.json`).

## Conséquences

- `Core\Support\Integration\Security` devient un nouveau sous-espace du socle partagé, dédié à la sécurité des appels sortants — cohérent avec le rôle déjà établi de `core/support` (G3d).
- `packages/payment` gagne une nouvelle table (`payment_provider_applications`) et un nouveau port de livraison (`Domain\Port\PaymentCallbackSender`) — aucune modification du graphe de modules (`ModuleGraph.php` inchangé).
- `packages/notification` gagne un value object Domain (`ScopedSessionRef`) — aucune nouvelle persistance, conforme à l'intention d'ADR-0042.
- Trois commandes CLI supprimées (`wallet:credit`, `wallet:debit`, `admin:current-otp`), une commande test-only ajoutée (`test:debit-wallet-once`, jamais enregistrée hors de l'environnement `testing`).
- Deux nouvelles routes publiques (`POST /v1/payments/paylink`, `POST /v1/payments/payin`), additives — `POST /v1/payments` reste inchangé pour la rétrocompatibilité (G9).
