# 08 — API pour l'intégrateur

*Ce chapitre est la référence technique de l'API : le contrat d'appel, les refus, les limites. La marche à suivre est dans le [parcours F14](../parcours/README.md) et dans le guide d'intégration ([`docs/integration/`](../../integration/)).*

Ce chapitre est le contrat d'appel. Il ne répète pas le [guide `curl`](../../integration/guide-curl-integrateur.md),
qui contient les commandes exécutées, ni le [kit Laravel](../../integration/kit-laravel/INSTALL.md),
qui contient le code. Il dit **pourquoi** l'API se comporte comme elle se comporte — parce
que la plupart des refus que vous vas recevoir sont des refus informés.

---

## 1. Le contrat, en six points

1. **Toutes les routes d'intégration sont sous `/v1`.** La version est dans l'URL, pas dans
   un en-tête. Une version majeure nouvelle vit à côté ; l'ancienne est dépréciée, puis
   retirée.
2. **L'authentification est un jeton porteur** : `Authorization: Bearer <clé>`.
3. **Toute mutation d'argent exige `Idempotency-Key`.** Treize routes, pas d'autres.
4. **Les erreurs sont au format RFC 7807** (`application/problem+json`), avec `type`,
   `title`, `status`, `detail`, `correlation_id`. En `422`, un champ `errors` complète.
5. **Les montants sont des entiers en unités mineures.** Jamais de virgule, jamais de
   flottant.
6. **Les réponses sont minimales.** L'API ne renvoie que ce dont l'appelant a besoin.

Le document de référence est `openapi/v1.json`, explorable sur `/docs/api` avec un bouton
`« Try it out »`.

---

## 2. La clé d'API

La clé a la forme `pk_XXXXXXXX.XXXXXXXXXXXXXXXX` : un **préfixe** de 8 caractères, un point,
un **secret** de 32 octets en base64url.

| Partie | Visible ? |
|---|---|
| Préfixe | oui, dans les journaux et la console |
| Secret | **jamais** après la création |

La clé est **nominative** et **portée** : chaque appel vérifie que la clé a la portée
requise. Un appel hors portée reçoit `403` : `« The {scope} scope is required. »`

Les **23 portées** sont regroupées par capacité : `payments:read/write`, `webhooks:read/write`,
`notifications:read/write`, `notification_channel_sessions:read/write`, `wallets:read/write`,
`customers:read/write`, `plans:read/write`, `subscriptions:read/write`, `invoices:read/write`,
`usage:read/write`, `portal:write`, `payouts:read/write`, `exports:write`. La portée joker
`*` les couvre toutes.

`GET /v1/whoami` ne demande **aucune portée** et renvoie ce que votre clé vaut vraiment :

```json
{"project_id":"…","organization_id":"…","scopes":["*"]}
```

### Les cas d'erreur

| Cas | Code | `type` |
|---|---|---|
| Pas d'en-tête `Authorization` | 401 | `unauthenticated` |
| Clé inconnue, révoquée ou expirée | 401 | `unauthenticated` (même message, pour ne pas révéler ce qui existe) |
| IP hors des blocs CIDR autorisés | 403 | `key-origin-not-allowed` |
| Projet suspendu | 403 | `project-suspended` |
| Projet archivé | 403 | `project-archived` |
| Portée manquante | 403 | `forbidden` |

**Utilisez `GET /v1/whoami` en premier.** C'est le plus rapide moyen de vérifier qu'une clé
fonctionne et qu'elle a les bonnes portées.

---

## 3. L'idempotence

`Idempotency-Key` est un en-tête HTTP, une chaîne non vide, 255 caractères maximum.
**Une valeur par intention** : l'identifiant de votre commande, par exemple.

**Les 13 routes qui l'exigent :**

```
POST /v1/payments              POST /v1/payments/paylink
POST /v1/payments/payin        POST /v1/payments/{paymentId}/simulate
POST /v1/wallets/transfers     POST /v1/wallets/withdrawals
POST /v1/subscriptions         POST /v1/subscriptions/{id}/change-plan
POST /v1/invoices              POST /v1/invoices/{id}/issue
POST /v1/invoices/{id}/void    POST /v1/invoices/{id}/pay
POST /v1/exports
```

### Ce que fait le Core

| Situation | Réponse |
|---|---|
| En-tête absent ou vide | `400 idempotency-key-required` |
| Clé inconnue | la requête s'exécute normalement |
| Clé connue, **même corps**, terminée | **la réponse d'origine est renvoyée**, le handler n'est pas réexécuté. Aucun second effet. |
| Clé connue, **corps différent** | `409 idempotency-key-conflict` |
| Clé connue, **en cours** (requête concurrente) | `409 idempotency-key-conflict` |
| La requête a échoué | la clé est **libérée** : vous pouvez réessayer avec la même clé |

La portée de la clé est `(projet, clé)` : deux projets peuvent utiliser la même clé sans
collision.

> **Un secret n'est jamais rejoué.** Si la réponse d'origine contenait un secret (le
> `callback_secret` d'un paiement), ce secret **n'est pas** dans la réponse rejouée.
> Récupérez-le au premier appel et conservez-le.

La clé est retenue **24 heures**.

---

## 4. Les quotas de débit

Quatre plans, cumulatifs. Un dépassement répond `429` avec un en-tête `Retry-After`.

| Plan | Limite |
|---|---|
| Global, par adresse IP | 600/min |
| Global, par clé | 300/min |
| Par projet | 3 000/min |
| Par clé d'API | 1 500/min |
| Classe `payments:write` | 600/min |
| Classe `notifications:write` | 600/min |

Les classes ne s'appliquent qu'aux **méthodes d'écriture** : `GET`, `HEAD` et `OPTIONS` en
sont exemptées.

Chaque réponse porte `RateLimit-Limit`, `RateLimit-Remaining` et `RateLimit-Reset`, qui
décrivent le seau **le plus contraignant**. C'est la valeur que vous devez surveiller.

Les seuils sont surchargeables par projet (par la plateforme), et le compteur est
**fail-open** : si l'infrastructure de compteur est indisponible, la requête passe et le
Core journalise un avertissement. La protection ne doit pas devenir une panne.

---

## 5. Les trois entrées en lecture du commerce

Ce sont les 27 opérations `Billing` de l'API. Le cycle est toujours le même.

### Le client

```
PUT  /v1/customers/{externalRef}      déclare ou met à jour un client
GET  /v1/customers/{externalRef}      lit un client
GET  /v1/customers                   liste les clients
POST /v1/customers/{externalRef}/suspend
POST /v1/customers/{externalRef}/reactivate
POST /v1/customers/{externalRef}/erase
```

`externalRef` est **votre** identifiant opaque, format `[A-Za-z0-9._:-]{1,128}`. Le `PUT`
est **idempotent** : le même `externalRef` et le même corps ne créent pas de doublon.

L'**effacement** est définitif : l'identité est détruite, la ligne reste pour que les
factures passées gardent leur sens.

### L'offre

```
GET  /v1/plans
POST /v1/plans
POST /v1/plans/{code}/versions
POST /v1/plans/{code}/versions/{version}/publish
POST /v1/plans/{code}/archive
```

Une version **publiée est figée**. Publier, c'est s'engager.

### L'abonnement et la facture

```
POST /v1/subscriptions
GET  /v1/subscriptions
GET  /v1/subscriptions/{id}
POST /v1/subscriptions/{id}/cancel
POST /v1/subscriptions/{id}/change-plan

GET  /v1/invoices
POST /v1/invoices
GET  /v1/invoices/{invoiceId}
GET  /v1/invoices/{invoiceId}/document
POST /v1/invoices/{invoiceId}/issue
POST /v1/invoices/{invoiceId}/void
GET  /v1/invoices/{invoiceId}/payments
POST /v1/invoices/{invoiceId}/pay
```

> **Un abonnement n'est pas encaissé.** Une période déclarée payée est un fait enregistré.
> Le chemin qui **bouge l'argent** est `POST /v1/invoices/{id}/pay` ou un reçu hors ligne.
> Et un règlement de facture est crédité sur `collections`, jamais sur `revenue` : le
> compter deux fois serait faux.

---

## 6. Les paiements

Trois points d'entrée, deux usages :

| Route | Usage |
|---|---|
| `POST /v1/payments/paylink` | **lien de paiement hébergé** — le client paie sur une page |
| `POST /v1/payments/payin` | **charge directe** — votre application déclenche et attend |
| `POST /v1/payments` | le point d'entrée historique, toujours actif, **plus le chemin recommandé** |

Le corps est identique pour les trois :

| Champ | Obligatoire | Rôle |
|---|---|---|
| `channel` | oui | `mtn_momo`, `orange_money`, `card`… |
| `amount` | oui | en unités mineures |
| `currency` | oui | 3 caractères |
| `reason` | oui | votre référence interne |
| `customer_phone_number`, `customer_name`, `customer_email`, `customer_lang` | non | les informations du payeur |
| `flow` | non | le comportement du lien |
| `return_url` | non | redirection navigateur après le paiement |
| `callback_url` | non | notification serveur-à-serveur, signée |
| `wallet_recipient_external_ref` | non | **demande explicite** de créditer un portefeuille |

**`payin` est lent** : comptez 15 à 20 secondes. Votre client HTTP doit avoir un délai
d'au moins 60 secondes.

### Les codes de retour

| Code | Cause |
|---|---|
| `201` | paiement créé |
| `400` | `Idempotency-Key` absente |
| `403` | portée manquante |
| `409` | conflit d'idempotence |
| `422` | aucun prestataire pour ce canal, devise non supportée, ou corps invalide |
| `502` | le prestataire a rejeté |
| `503` | le prestataire est indisponible |

### Lire un paiement

```
GET /v1/payments/{paymentId}
```

Lit l'état **du Core**, pas du prestataire. Pour savoir ce que le prestataire sait, c'est la
**réconciliation** (chapitre 05).

```
GET /v1/payments?cursor=…&limit=…&status=…&created_from=…&created_to=…
```

`limit` va de 1 à 100. `created_from` est **inclusif**, `created_to` est **exclusif**. La
réponse est `{data: [...], next_cursor: "…"}` : le curseur est opaque, vous ne le construisez
pas.

### Le simulateur de paiement (staging uniquement)

`POST /v1/payments/{paymentId}/simulate`

```json
{"status": "succeeded", "reason": "Insufficient balance"}
```

`status` est obligatoire, parmi `succeeded`, `failed`, `expired`, `canceled`. `reason` est
facultatif.

**Quand c'est disponible :** uniquement sur un déploiement où `PAYMENT_SIMULATOR_ENABLED`
est vrai — c'est le **staging**, jamais la production. Et seulement pour les paiements
confiés au simulateur, c'est-à-dire créés pendant que le projet n'avait **aucune
application de prestataire** enregistrée.

**Ce que ça fait :** exactement ce que ferait un vrai callback — même changement de statut,
même crédit de portefeuille si vous en aviez demandé un, même événement
`payment.status_changed`. Ce n'est pas une simulation de façade.

**Ce que ça ne fait pas :** si vous voulez tester contre le vrai prestataire depuis le
staging, enregistrez une application de prestataire pour le projet dans la console — le
Core l'utilisera à la place du simulateur.

Le refus `409 payment-not-simulable` signifie : simulateur désactivé, paiement non
simulable, ou paiement déjà final.

---

## 7. Les notifications

Trois endpoints, un par type de contenu :

```
POST /v1/notifications/text    channel, to_party_ref, text
POST /v1/notifications/image   channel, to_party_ref, media_url, caption?, filename?, mimetype?
POST /v1/notifications/file    channel, to_party_ref, media_url, filename, mimetype
```

| Champ | Rôle |
|---|---|
| `channel` | `whatsapp` — le seul canal routable aujourd'hui |
| `to_party_ref` | **votre** référence de destinataire. **Jamais** un numéro de téléphone. |
| `variables.phone_number` | le numéro E.164 du destinataire |
| `variables.session` | **obligatoire** : le nom d'une session WhatsApp enregistrée |
| `variables.customer_ref` | le client facturé. Si son quota est dépassé : `429`, et **rien n'est envoyé**. |
| `client_reference` | clé de déduplication : même valeur = **un seul envoi** |
| `media_url` | une URL **publique**, téléchargeable sans authentification |

> **`variables.session` n'est pas optionnelle.** Un nom de session non enregistré donne
> `422 channel-session-not-found`, et **rien n'est écrit ni envoyé**. Il n'existe aucune
> session par défaut : l'administrateur de la plateforme en crée une dans la console
> (`« Sessions WhatsApp »`), et vous en récupérez le nom.

### Le piège du code 201

La réponse est **`201` dans tous les cas**, y compris quand l'envoi a échoué :

```json
{"id":"…","channel":"whatsapp","status":"sent","content_type":"text","error_message":null}
```

**Testez toujours `status` et `error_message`.** Le cycle est `queued` → `sent` →
`delivered` → `read`, ou `failed`. Une session enregistrée mais un téléphone non lié donne
`201` avec `status: "failed"`.

Suivre un envoi : `GET /v1/notifications/{notificationId}`, lister : `GET /v1/notifications`
avec `status`, `channel`, `created_from`, `created_to`.

> **Les notifications n'ont pas d'`Idempotency-Key` HTTP.** La déduplication est au niveau
> métier, par le champ `client_reference`. Sans lui, chaque requête est un envoi neuf.

---

## 8. Les webhooks sortants

```
POST   /v1/webhook-endpoints
GET    /v1/webhook-endpoints
DELETE /v1/webhook-endpoints/{endpointId}
POST   /v1/webhook-endpoints/{endpointId}/test
POST   /v1/webhook-endpoints/{endpointId}/rotate-secret
```

Le corps de création :

```json
{"url": "https://example.com/webhooks/core", "events": ["payment.status_changed"]}
```

`events` accepte `*` ou une liste de types. La réponse `201` porte le **secret**
(`whsec_…`), **une seule fois**.

Le test (`POST .../test`) envoie un événement `webhook.test` signé, **synchrone**, jamais
retenté et jamais compté comme une livraison. C'est le moyen le plus rapide de valider
votre vérificateur de signature.

La rotation du secret est détaillée dans le chapitre 03 (double signature pendant la
fenêtre de recouvrement).

### La non-double-notification

Si un paiement a son **propre `callback_url`**, l'état est notifié **sur ce callback**, et
**pas aussi** sur les endpoints webhook du projet. Choisissez l'un ou l'autre, pas les
deux.

---

## 9. L'usage et les quotas

```
POST /v1/usage
GET  /v1/usage
GET  /v1/customers/{externalRef}/entitlements
```

`POST /v1/usage` — corps :

| Champ | Rôle |
|---|---|
| `meter` | **obligatoire** — le compteur. Format `[a-z0-9_.]{1,64}` |
| `quantity` | **obligatoire** — entier, jamais zéro. Négatif = compensation. |
| `idempotency_key` | **obligatoire** — c'est un **champ de corps**, pas un en-tête. |
| `customer_ref` | le client imputé |
| `occurred_at` | quand l'usage a eu lieu |

> **Ici, la clé d'idempotence est dans le corps.** Un `Idempotency-Key` en en-tête est
> ignoré sur cette route.

Les codes : `201` (compté), `200` (déjà compté — décision rejouée), `429 quota-exceeded`,
`409` (la même clé pour un autre client ou une autre quantité), `422`.

**Un refus n'écrit rien** — ni journal, ni compteur, ni clé. Vous pouvez rejouer plus tard.

`GET /v1/customers/{externalRef}/entitlements` renvoie ce que le client a le droit de
faire : ses limites, ses fonctionnalités, et son compteur.

---

## 10. Les portefeuilles

```
POST /v1/wallets/transfers        transfert à plusieurs jambes
POST /v1/wallets/withdrawals      sortie enregistrée
GET  /v1/wallets/{walletId}/balance
GET  /v1/wallets/{walletId}/transactions
GET  /v1/parties/{externalRef}/wallets
```

**Il n'existe pas de crédit libre.** La valeur n'entre que par un paiement confirmé avec
`wallet_recipient_external_ref`, ou par un transfert à deux jambes. Un endpoint « créditer
un compte » serait une porte ouverte.

Un transfert porte **1 à 20 jambes**, chaque jambe est `from` / `to` / `amountMinor`, et la
somme est nulle : la valeur se déplace, elle ne se crée pas. Si les fonds manquent :
`409 insufficient-funds`, et **rien n'est bougé**.

Les identifiants : `wallets:write` pour écrire, `wallets:read` pour lire.

---

## 11. Les exports

```
POST /v1/exports
GET  /v1/exports/{exportId}
```

`POST /v1/exports` répond **`202`** : l'export est construit en arrière-plan, pas pendant
votre requête. Le corps :

```json
{"id":"…","status":"queued","created_at":"…","finished_at":null,"expires_at":"…","error":null,"download_url":null,"download_url_expires_at":null}
```

Le même `Idempotency-Key` rend **le même export** — pas de seconde construction.

Les statuts : `queued`, `running`, `done`, `failed`. Un export en échec porte son `error`.

### Le téléchargement

> **Le téléchargement n'est pas sous `/v1`.** C'est une route signée :
> `GET /exports/{exportId}/download?expires=…&signature=…`

- **15 minutes** de validité.
- **Usage unique** : la première requête consomme le lien, la suivante reçoit `403`.
- **Aucune clé d'API nécessaire** : la signature est la seule authentification.
- L'archive est un `.zip` de fichiers **JSON Lines**, plus `manifest.json`.

Le format est détaillé dans le chapitre 06.

---

## 12. Le portail

```
POST /v1/portal/sessions
GET  /v1/portal/settings
PUT  /v1/portal/settings
```

`POST /v1/portal/sessions` exige la portée `portal:write`. Si vous déclarez un client
dans l'appel, il faut aussi `customers:write`. Si vous souscrivez à une offre, il faut
aussi `subscriptions:write`.

La réponse `201` :

```json
{"url":"…","expires_at":"…","customer_created":false,"subscription_created":false}
```

Le lien est **à usage unique** et **valable 10 minutes**.

Le `return_url` est **vérifié avant toute écriture** contre la liste blanche du projet. Une
adresse hors liste donne `422`.

Le détail du portail est dans le chapitre 07.

---

## 13. La répartition des rôles entre vous et l'administrateur

| Vous décidez | L'administrateur de la plateforme décide |
|---|---|
| Le nom de vos clés, leur portée, leur expiration, leur restriction CIDR | Que la clé existe ; il la crée dans la console et vous la transmet |
| Le contenu de vos endpoints webhook et leurs catégories | Rien : vous êtes autonome sur `/v1/webhook-endpoints` |
| Vos clients, vos offres, vos abonnements, vos factures | Rien |
| Le nom de votre compteur d'usage et la façon dont vous mesurez | Les quotas attachés aux offres, et la correspondance entre une capacité et un compteur dans les réglages du commerce |
| La **forme** de vos clients : la clé unique que vous désignez, le format de vos références, les champs que vous attendez du Core | **L'acceptation** de cette forme : l'opérateur la lit dans la console (`« Forme du client »`) et la met en vigueur — ou la refuse. Une proposition n'est jamais une décision |
| La session WhatsApp que vous nommez dans `variables.session` | **La session elle-même** : elle est créée dans la console, `« Sessions WhatsApp »`. Il vous en donne le nom. |

> **La session WhatsApp est le point de friction le plus courant.** Elle est créée par
> l'administrateur, pas par vous. Sans elle, aucun envoi WhatsApp ne part. Demandez-la
> explicitement.

---

## Repères techniques

| Comportement | Fichier |
|---|---|
| Spécification | `openapi/v1.json` (49 chemins, 57 opérations) |
| Routes par module | `packages/*/routes/api.php` |
| Authentification et portée | `packages/identity/src/Presentation/Http/Middleware/ResolveProjectMiddleware.php`, `packages/identity/src/Contracts/ApiKeyScopes.php` |
| Idempotence | `packages/support/src/Http/Middleware/RequireIdempotencyKey.php` |
| Quotas de débit | `app/Http/Middleware/AuthenticateProjectWithRateLimit.php`, `config/ratelimit.php` |
| Simulateur | `packages/payment/src/Presentation/Http/Controllers/SimulatePaymentController.php` |
| Notifications | `packages/notification/src/Presentation/Http/Requests/Send*NotificationRequest.php` |
| Webhooks | `packages/webhook/routes/api.php` |
| Export | `packages/export/routes/api.php`, `packages/export/routes/download.php` |
| Exemples exécutés | `docs/integration/guide-curl-integrateur.md`, `docs/integration/webhooks.md` |
