# Plan d'implémentation — durcissement, observabilité, clés, statuts, console, doc

**Statut** : décisions D1–D5 validées le 2026-10-03 (voir fin du document) ; implémentation phase par phase.
**Base** : [audit du 2026-10-03](../audit/audit-2026-10-03.md) et demandes du 2026-10-03.
**Règle** : une phase = une PR = `make ci` + `make guard-test` verts. Chaque décision
structurante fait l'objet d'une ADR (la phase la cite). Le graphe de modules reste
inchangé, sauf mention contraire.

---

## 0. Ce que l'analyse a révélé (causes racines)

| # | Constat | Où | Conséquence |
|---|---|---|---|
| R1 | **Modifier une application provider échoue avec « duplicate key »** : la contrainte `UNIQUE(provider_code, public_key)` est globale à tous les projets. Le contrôleur attrape `RuntimeException`, dont hérite `QueryException`, et **affiche le message SQL brut** | `ProviderApplicationsController::store`, migration `payment_provider_applications` | Message incompréhensible. Impossible de réutiliser une clé ou de corriger une saisie. Pas d'écran d'édition. |
| R2 | **L'environnement (sandbox/live) n'existe que sur la clé API.** `payments`, `notifications`, `webhook_endpoints` et `payment_provider_applications` l'ignorent | `ProjectContext::$environment` n'est lu nulle part dans `payment`/`notification`/`webhook` | **Une clé sandbox déclenche un vrai paiement** avec l'application MyCoolPay du projet. Pas d'isolation des données. |
| R3 | **Clés API illimitées** par projet et par environnement | `IssueApiKey`, `api_keys` (aucune contrainte) | Clés oubliées actives, surface d'attaque, confusion. |
| R4 | **La corrélation se perd** : `HandlePaymentStatusChangedUseCase` écrit l'outbox avec `correlationId: null`. Le relais ne restaure pas de contexte, et le webhook entrant provider ouvre une corrélation neuve | `packages/payment`, `OutboxRelay` | Impossible de suivre un paiement de la requête jusqu'au webhook sortant. |
| R5 | **Aucune destination de logs centralisée.** Logs JSON sur stdout de chaque conteneur, sans trace distribuée ni métrique | `config/logging.php`, `compose.prod.yaml` | Le débogage en production se fait à l'aveugle. |
| R6 | **« Final » est ambigu** : `isTerminal()` met `succeeded` en terminal, alors que `refunded` peut le suivre. Aucune machine d'états explicite, aucun historique des transitions | `PaymentStatus`, `Payment::withStatusChange` | Impossible de dire à l'intégrateur ce qui est encore faisable sur une transaction. |
| R7 | **La console n'affiche qu'une partie des données** : le détail paiement montre 11 champs sur 20 (ni provider, ni référence provider, ni dates, ni `return_url`, ni wallet, ni historique) | `resources/views/admin/**` | Pas de page notifications. Pas de détail de livraison webhook (seulement la DLQ). Pas de détail wallet. |
| R8 | Payload webhook ≠ OpenAPI, fuite de `provider_code`, SSRF par redirection, IP client faussée, etc. | voir l'audit | — |

---

## Phase 1 — Correctifs de l'audit (sécurité et webhooks) · *petite PR, sans rupture*

1. `LaravelHttpTransport` : `allow_redirects => false`. Un `3xx` devient un échec
   permanent avec un message clair. Épinglage de l'IP validée (`CURLOPT_RESOLVE`) pour les
   URL fournies par un tiers (webhooks, `callback_url`).
2. `OutboundUrlGuard` : normaliser les IPv4 mappées (`::ffff:`) et bloquer
   `100.64.0.0/10`, `64:ff9b::/96` et `198.18.0.0/15`. Tests à partir de la table de l'audit.
3. HTTPS obligatoire pour `url` (webhook) et `callback_url` en environnement `live`.
4. `trustProxies` : liste explicite du sous-réseau Docker des proxies (configurable
   `TRUSTED_PROXIES`). Test qui vérifie `$request->ip()` avec une chaîne XFF à deux sauts.
5. Webhooks :
   - pas d'envoi ni de rejeu vers un endpoint inactif (la livraison passe en `canceled`) ;
   - `failed()` sur `DeliverWebhookJob` qui passe la ligne en `dead` ;
   - commande planifiée `webhook:sweep` qui relance les `pending` orphelins.
6. Limiteur `api` : seau supplémentaire par IP pour les requêtes non authentifiées.
7. Purge planifiée (`model:prune`) d'`event_outbox` publié et de `webhook_deliveries`
   terminées. Durée configurable, 90 jours par défaut.

**Critères d'acceptation** : un test par point ; `make guard-test` inchangé.

---

## Phase 2 — Observabilité : « tout voir, de la requête à la réponse » · *priorité utilisateur*

Deux couches complémentaires.

### 2A. Couche technique : traces, logs et métriques centralisés

- **Un identifiant par requête** (`correlation_id`, déjà présent) **propagé partout** :
  - aux jobs, via `Context` Laravel ;
  - à l'outbox, ce qui corrige R4 : `HandlePaymentStatusChanged` et tous les `append()`
    lisent la corrélation courante ;
  - au relais, qui restaure la corrélation de la ligne avant `event()` ;
  - aux appels sortants (en-tête `Correlation-Id` vers les providers) et aux webhooks
    sortants (en-têtes `X-Event-Id` et `X-Delivery-Id`).
- **Valider l'en-tête `Correlation-Id` entrant** (UUID ou ≤ 64 caractères `[A-Za-z0-9-]`),
  pour éviter l'injection de logs.
- **OpenTelemetry** (SDK PHP + auto-instrumentation Laravel, PDO, Guzzle, files) :
  une trace par requête HTTP, avec un span par requête SQL, appel provider et job.
  `trace_id` est ajouté à chaque ligne de log.
- **Middleware « journal d'échange HTTP »** : une ligne de log structurée à l'entrée et une à
  la sortie de chaque requête (méthode, route, Project, environnement, statut, durée,
  corps **rédigé**). Même chose pour chaque appel provider (`ExternalApiCaller`, déjà
  instrumenté, à compléter avec les corps rédigés) et chaque livraison webhook ou callback.
- **Point central** : *OpenTelemetry Collector* → backend de logs et traces + Grafana
  (voir la décision D1), ajouté à `compose.prod.yaml` avec une rétention configurée.
- **Tableaux de bord et alertes** livrés en code (provisioning Grafana) :
  - taux d'erreur et latence par route ;
  - paiements par statut ;
  - livraisons webhook en retard ou en DLQ ;
  - âge de la plus vieille ligne outbox non publiée ;
  - profondeur des files Horizon ;
  - callbacks provider rejetés (403).
- **Santé** : `/health` (vivacité) et `/ready` (PostgreSQL, Valkey, Horizon, âge de l'outbox),
  consommés par le monitoring.
- **Files Horizon dédiées** (`config/horizon.php`) : `webhooks`, `payment-callbacks`,
  `notifications`, `default`.

### 2B. Couche métier : la chronologie d'une transaction dans la console

Pour un paiement, une page **« Parcours »** affiche dans l'ordre chronologique :

```
① Requête reçue          POST /v1/payments/payin   corps rédigé, clé API (préfixe), env, IP
② Appel provider         POST MyCoolPay /payin       requête/réponse rédigées, durée, statut HTTP
③ Réponse envoyée         201                         corps renvoyé à l'intégrateur
④ Webhook provider reçu  MyCoolPay → Core            IP, signature OK/KO, corps rédigé
⑤ Transition de statut   pending → succeeded         source : webhook | réconciliation
⑥ Crédit wallet          (si demandé)                écriture ledger, solde après
⑦ Événement publié       payment.status_changed      id d'événement
⑧ Livraisons             webhook endpoint X / callback_url   chaque tentative : statut HTTP, durée, réponse, prochain essai
```

Chaque étape porte un lien **« voir la trace technique »** (vers Grafana, par `trace_id`).

**Conception, conforme à G3c** (aucune nouvelle arête dans le graphe) :

- `payment` persiste ses propres étapes dans `payment_timeline_entries`
  (`payment_id`, `type`, `occurred_at`, `correlation_id`, `trace_id`, `summary`,
  `request`/`response` JSONB rédigés). Ce sont les étapes ①②③④⑤⑥, plus les tentatives de
  callback, qui deviennent **persistées** (corrige aussi « callbacks sans DLQ » de l'audit).
- `webhook` persiste **chaque tentative** dans `webhook_delivery_attempts` (aujourd'hui,
  seule la dernière est gardée).
- Ports de lecture `Contracts` : `Payment\Contracts\GetPaymentTimeline` et
  `Webhook\Contracts\ListDeliveriesForEvent`. La console compose les deux.
- Même mécanisme ensuite pour les notifications (requête, chaque provider tenté, ack WAHA,
  webhooks).
- **Rédaction** : les secrets (clés, signatures, `Authorization`, `private_key`, secrets
  de callback) sont **toujours** masqués. Les données métier (téléphone, montant, nom)
  restent visibles aux opérateurs autorisés (décision D5).

**Critères d'acceptation** : un paiement fait en sandbox affiche ses étapes ①→⑧ dans la
console. Un `correlation_id` copié depuis la réponse API retrouve toute la trace dans
Grafana, y compris le job de livraison webhook exécuté trois minutes plus tard.

**ADR** : « Observabilité : stack centralisée + chronologie métier par transaction ».

---

## Phase 3 — Statuts de paiement : intermédiaires vs finaux, opérations permises

- **Machine d'états explicite** dans le Domain (`PaymentStatus::canTransitionTo()`) :

  | Statut | Nature | Opérations encore possibles |
  |---|---|---|
  | `pending` | intermédiaire | `submit_action` (OTP/USSD), `cancel`, vérification du statut |
  | `processing` | intermédiaire | vérification du statut uniquement |
  | `succeeded` | **final** (pour l'encaissement) | `refund` (si le provider le permet, futur) |
  | `failed` / `expired` / `canceled` | **final** | aucune ; créer un nouveau paiement |
  | `refunded` | **final** | aucune |

- Les transitions interdites sont **refusées et tracées**. Exemple : un webhook tardif
  `pending` après `succeeded` est ignoré, et une entrée de chronologie
  `transition_rejected` est créée.
- La réponse API et les webhooks exposent `is_final: bool` (et `allowed_actions: []` si D3
  le valide).
- Remplacer `isTerminal()` par `isFinal()` aux appelants (réconciliation, rejeu
  idempotent). Le cas `succeeded` → `refunded` est documenté.
- Historique des transitions : entrées `status_changed` de la chronologie (phase 2B).
- Documentation intégrateur : tableau ci-dessus et règle « ne livrez la commande que sur
  `succeeded` ; tant que `is_final=false`, attendez le webhook ou interrogez ».

**ADR** : amendement d'ADR-0035 (vocabulaire inchangé, sémantique final/intermédiaire).

---

## Phase 4 — Environnements, clés API et applications provider (refonte)

### 4A. Isolation sandbox / live (corrige R2, prérequis aux deux suivants)

- Colonne `environment` sur `payments`, `notifications`, `webhook_endpoints`,
  `webhook_deliveries` et `payment_provider_applications`, remplie depuis `ProjectContext`.
- Une clé sandbox ne voit et ne crée **que** des données sandbox. Une clé live ne voit
  **que** des données live. La console filtre par environnement, avec un bandeau
  « SANDBOX » visible.
- Comportement des paiements en sandbox : voir la décision D2.

### 4B. Clés API : une live et une sandbox par projet

- Contrainte en base : `UNIQUE(project_id, environment)` sur `api_keys`.
- Actions dans la console et l'API admin :
  - **Générer** : uniquement si aucune clé n'existe pour cet environnement.
  - **Régénérer (remplacer)** : supprime l'ancienne et crée la nouvelle dans **une seule
    transaction**. Le secret est affiché une seule fois.
  - **Révoquer** : **supprime** la clé ; l'entrée d'audit conserve la trace (préfixe,
    auteur, date).
- La page projet affiche deux cartes, « Clé live » et « Clé sandbox » : préfixe, scopes,
  date de création, dernière utilisation (`last_used_at`, nouvelle colonne mise à jour
  au plus une fois par minute).
- Migration des données existantes : voir la décision D4.

### 4C. Applications provider : refonte (corrige R1)

- Modèle : **une application par (projet, provider, environnement)**, avec un `id`
  propre, un libellé, une clé publique, une clé privée chiffrée, `created_at` et
  `rotated_at`.
- **Écrans** : liste par projet (live et sandbox), **création**, **édition**
  (remplacer la clé publique et/ou privée ; un champ privé vide signifie « inchangée »),
  suppression.
- **Validation applicative avant la base** : si la clé publique est déjà utilisée, le
  message est : « Cette clé publique est déjà rattachée au projet *X* (live). Une
  application MyCoolPay ne peut servir qu'un seul projet et environnement, car le Core
  l'utilise pour attribuer chaque callback. »
- **Plus jamais de message SQL affiché** : les contrôleurs n'attrapent plus
  `RuntimeException` en vrac. Les exceptions de domaine ont des messages en français,
  et les autres erreurs donnent une erreur générique avec `correlation_id`.
- Bouton **« Tester la connexion »** : appel en lecture seule au provider avec les clés
  saisies, pour savoir avant d'enregistrer si elles sont valides.
- La clé privée n'est **jamais** réaffichée ; la clé publique est affichée masquée
  (`pk_…8f3a`).

**ADR** : « Isolation par environnement », « Une clé API par environnement »,
« Applications provider par environnement » (amende ADR-0051 §2).

---

## Phase 5 — Contrat API : le strict nécessaire (requêtes et réponses)

- **Paiement, réponse à la création** : `id`, `status`, `is_final`, `amount`, `currency`,
  `channel`, `created_at`, plus selon le flux : `payment_url` (paylink),
  `action_hint` (payin USSD), `callback_secret` (si `callback_url`, une seule fois).
  Ce qui est retiré :
  - `idempotency_key` : l'intégrateur le connaît déjà ;
  - `callback_url` : il l'a envoyé ;
  - `requires_action` : redondant avec la présence de `action_hint` ;
  - les `null` superflus.
- **Paiement, lecture** : la même forme + `failure_reason` (si statut final en échec) +
  `updated_at`.
- **Webhook `payment.status_changed`, payload canonique unique pour les deux canaux**
  (endpoint et `callback_url`) :

  ```json
  {"id":"evt_…","type":"payment.status_changed","created_at":"…",
   "data":{"payment_id":"…","reference":"<votre Idempotency-Key>","status":"succeeded",
           "is_final":true,"amount":5000,"currency":"XAF","failure_reason":null}}
  ```

  Ce qui est retiré : `provider_code` et `transaction_ref` (internes, ADR-0038).
- **Requêtes vers les providers** : n'envoyer que les champs requis par le provider ;
  les champs client optionnels ne partent que s'ils sont fournis.
- **Erreurs** : RFC 7807 inchangé, sans détail interne (déjà le cas pour `502`, à
  généraliser).
- **Test de contrat** : le payload émis est validé contre le schéma OpenAPI, ce qui ferme
  la dérive de l'audit §4.2.
- Version : voir la décision D3 (modifier `/v1` ou publier `/v2`).

---

## Phase 6 — Console : afficher toutes les données, partout

Inventaire, puis complétion :

| Page | Aujourd'hui | Cible |
|---|---|---|
| Paiement — détail | 11 champs | **Tous** les champs (provider, référence provider, `return_url`, wallet, projet, environnement, dates) + **Parcours** (2B) + livraisons |
| Notifications | aucune page web | Liste + détail : contenu, destinataire, canal, session, chaque tentative provider, acks, webhooks |
| Livraisons webhook | DLQ seulement | Toutes les livraisons (filtres statut, événement, endpoint) + détail : payload, en-têtes envoyés (signature masquée), **chaque tentative** avec la réponse, bouton de rejeu |
| Endpoint webhook | création seule | Détail : URL, événements, statut, taux de succès, dernières livraisons, édition, rotation du secret |
| Wallet | liste + ledger | Détail : propriétaire, solde, écritures, paiements à l'origine des crédits |
| Projet | partiel | Clés (4B), applications provider (4C), endpoints, sessions WhatsApp, environnements |
| Journal d'audit | liste | Détail avant/après (diff) |

Règle transverse : chaque identifiant est cliquable (paiement → projet → wallet →
écriture), et chaque page de détail montre `correlation_id` avec le lien vers la trace.

---

## Phase 7 — Documentation intégrateur fonctionnelle et testable

- **Portail `/docs/api`** (Scramble « Try it », déjà présent) : exemples réels sur
  chaque route, utilisables directement avec une **clé sandbox**.
- **Guides** (`docs/integration/`), chacun vérifié par un test automatisé qui exécute ses
  exemples :
  1. Démarrage rapide sandbox (5 minutes jusqu'au premier paiement).
  2. Paiements : flux paylink et payin, statuts intermédiaires et finaux, opérations
     permises.
  3. **Recevoir les webhooks** : vérification de signature (PHP, Node, Python),
     horodatage, déduplication, ordre, retries, DLQ, rejeu.
  4. Notifications WhatsApp.
  5. Erreurs : catalogue complet avec la conduite à tenir pour chacune.
- **Endpoint de test** : `POST /v1/webhook-endpoints/{id}/test` envoie un `ping` signé.
- **Simulateur sandbox** (si D2 le retient) :
  `POST /v1/sandbox/payments/{id}/simulate {"status":"succeeded"}`, pour tester le
  parcours complet jusqu'au webhook sans argent réel.
- **Collection Postman/Bruno** générée depuis l'OpenAPI dans la CI.
- Correction ou archivage d'`architecture-integration-externe.md` (en-têtes et nombre de
  tentatives obsolètes).

---

## Ordre proposé et estimation

| Ordre | Phase | Pourquoi à ce rang | Taille |
|---|---|---|---|
| 1 | Phase 1 — correctifs de l'audit | Risques de sécurité, petits et sans rupture | S |
| 2 | Phase 2 — observabilité | Votre priorité. Elle rend aussi les phases suivantes vérifiables. | L |
| 3 | Phase 4 — environnements, clés, applications provider | Bug bloquant (R1) et isolation sandbox (R2) | L |
| 4 | Phase 3 — statuts | Prépare le contrat de la phase 5 | M |
| 5 | Phase 5 — contrat API minimal | Rupture de contrat : à faire une seule fois, après 3 et 4 | M |
| 6 | Phase 6 — console complète | S'appuie sur 2B, 3 et 4 | L |
| 7 | Phase 7 — documentation et sandbox | Décrit l'état final | M |

---

## Décisions à prendre ensemble

- **D1 — Stack de monitoring** : (a) auto-hébergée sur le VPS : OpenTelemetry Collector
  + Grafana + Loki (logs) + Tempo (traces) + Prometheus (métriques), environ 2–3 Go de RAM
  supplémentaires, licence AGPL pour Grafana/Loki/Tempo (usage interne non modifié) ;
  (b) SaaS (Grafana Cloud, Better Stack, Sentry) : rien à opérer, coût mensuel, données
  hors de vos serveurs ; (c) auto-hébergée sous licences Apache uniquement
  (VictoriaLogs + Jaeger + VictoriaMetrics + Grafana).
- **D2 — Comportement sandbox des paiements** : (a) simulateur interne, sans appel
  provider, statut piloté par l'endpoint de simulation ; (b) identifiants sandbox réels
  du provider (si MyCoolPay en fournit) ; (c) les deux.
- **D3 — Rupture de contrat** : modifier `/v1` directement (si aucun intégrateur
  n'est en production) ou publier `/v2` et garder `/v1` pendant une fenêtre de
  dépréciation (G9).
- **D4 — Clés API existantes en surnombre** : garder la plus récente par environnement
  et supprimer les autres, ou les lister avant toute suppression.
- **D5 — Données personnelles dans les journaux** : téléphones et noms visibles en
  clair pour les opérateurs autorisés (avec rétention de 90 jours), ou masqués
  partiellement (`+2376••••12`).

### Décisions validées (2026-10-03)

| # | Décision retenue |
|---|---|
| D1 | Stack auto-hébergée sur le VPS : OpenTelemetry Collector + Grafana + Loki + Tempo + Prometheus. |
| D2 | Simulateur interne par défaut en sandbox, **et** possibilité d'utiliser les identifiants sandbox du provider quand il en fournit. |
| D3 | Aucun intégrateur en production : `/v1` est modifié directement, et une ADR acte la rupture. |
| D4 | Pour chaque projet et environnement, la clé la plus récente est conservée ; les autres sont supprimées, avec une trace d'audit. |
| D5 | Téléphones et noms visibles en clair pour les opérateurs autorisés, avec une rétention de 90 jours. Les secrets sont toujours masqués. |
