# Guide d'intégration — commandes `curl` vérifiées

Les commandes de ce document ont été **exécutées pour de vrai** contre une instance
du Core branchée sur une vraie application MyCoolPay et un vrai WAHA (WhatsApp), puis
mises à jour le 2026-10-03 pour le contrat actuel (réponses minimales, `is_final`,
clés par environnement, simulateur sandbox) ; les réponses de cette mise à jour sont
celles de la suite de tests d'intégration. Identifiants et secrets remplacés par des
exemples.

**Commencez en sandbox** (§2.0) : aucun argent réel, et vous pilotez vous-même
l'issue de chaque paiement. Pour recevoir les événements (webhooks, `callback_url`)
et vérifier leur signature, lisez **[webhooks.md](webhooks.md)**.

Les charges utiles sont les mêmes que les exemples de la documentation interactive
(`/docs/api`, bouton « Try it out »), à deux nuances près, signalées : le numéro de
téléphone (à remplacer par le vôtre) et les URL de médias (qui doivent exister).

## 0. Préparation

```bash
export BASE_URL="https://core-system.visibilitycam.com"   # en local : http://localhost:8000
export API_KEY="pk_sandbox_XXXXXX.XXXXXXXXXXXXXXXX"        # clé du Project (voir ci-dessous)
export PHONE="+2376XXXXXXXX"                               # un numéro qui vous appartient, format E.164
export SESSION="ma-boutique"                               # nom de la session WhatsApp fourni par l'administrateur (§5)
```

- **Clés API** : un projet a **exactement deux clés**, une `pk_sandbox_…` et une
  `pk_live_…`, créées dans la console (`/admin/projects/<id>/api-keys/create`) et
  affichées **une seule fois**. Régénérer une clé remplace l'ancienne immédiatement ;
  révoquer la supprime. Chaque clé ne voit que les données de son environnement : un
  paiement créé en sandbox est introuvable (`404`) avec la clé live, et inversement.
- **En-têtes communs** : `Authorization: Bearer $API_KEY`, `Accept: application/json`,
  et `Content-Type: application/json` pour les requêtes avec corps.
- **Erreurs** : format RFC 7807 (`application/problem+json`) avec `type`, `title`,
  `status`, `detail`, `correlation_id` — citez le `correlation_id` au support.
- **`Idempotency-Key`** : **obligatoire** sur toutes les routes qui touchent de
  l'argent (création de paiement, simulation sandbox). Une valeur par intention de
  paiement — par exemple l'identifiant de votre commande ; rejouer la même clé renvoie
  la réponse d'origine sans débiter une seconde fois. Elle vous revient sous le nom
  `reference` dans les événements.
- **`Correlation-Id`** (optionnel) : envoyez votre propre identifiant de requête ; il
  est repris dans la réponse, les erreurs et les journaux du Core, ce qui permet au
  support de retrouver votre appel de bout en bout.
- **Montants** : entier, dans la plus petite unité de la devise. `XAF` n'a pas de
  sous-unité : `100` = 100 XAF. `USD`/`EUR` : `100` = 1,00.

## 1. Vérifier sa clé — `GET /v1/whoami`

```bash
curl -s "$BASE_URL/v1/whoami" \
  -H "Authorization: Bearer $API_KEY" \
  -H "Accept: application/json"
```

```json
{"project_id":"01a101a1-7a96-712d-b63a-e3bbc27ae721","organization_id":"01a101a1-7a88-7241-b0aa-99f174f09b01","environment":"sandbox","scopes":["*"]}
```

`environment` dit dans quel environnement vous travaillez avec cette clé.

Clé invalide ou révoquée → `401`.

## 2. Paiements

### 2.0 Sandbox : tester sans argent réel

Avec une clé `pk_sandbox_…`, si aucune application provider sandbox n'est enregistrée
pour le projet, le paiement est confié au **simulateur intégré** du Core : aucun appel
au fournisseur, aucun argent, aucune écriture comptable. Vous décidez de l'issue :

```bash
curl -s -X POST "$BASE_URL/v1/sandbox/payments/01a101a1-7b14-70de-874c-0e4586b565d0/simulate" \
  -H "Authorization: Bearer $API_KEY" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  --data '{"status":"failed","reason":"Insufficient balance"}'
```

```json
{"id":"01a101a1-7b14-70de-874c-0e4586b565d0","status":"failed"}
```

- `status` : `succeeded`, `failed`, `expired` ou `canceled` — tous finaux.
- Le changement de statut déclenche **exactement** ce qui se passerait en live :
  événement `payment.status_changed` vers vos endpoints sandbox (ou votre
  `callback_url`), crédit du wallet indiqué le cas échéant.
- Un paiement déjà final ne peut plus être simulé : `409 payment-not-simulable`.
- Avec une clé live : `403`. Un paiement live ne peut jamais être simulé.
- Un `paylink` sandbox renvoie une `payment_url` vers une page de paiement simulée
  du Core, où l'on clique « Payer » ou « Refuser » comme le ferait le client.

Pour tester contre le **vrai bac à sable du fournisseur**, enregistrez une application
provider sandbox dans la console : le Core l'utilise alors à la place du simulateur.

### En live

Le Project doit avoir une application MyCoolPay **live** enregistrée (console →
Project → *Applications provider*), sinon la création échoue en `422`.

### Ce que renvoie une création de paiement

Les trois routes de création renvoient la même forme, réduite au nécessaire :

| Champ | Présent | Description |
| --- | --- | --- |
| `id` | toujours | Identifiant du paiement. |
| `status` | toujours | Statut courant (voir [webhooks.md §3.1](webhooks.md#31-statuts-dun-paiement)). |
| `is_final` | toujours | `false` : attendez l'événement ; `true` : l'issue est acquise. |
| `amount`, `currency`, `channel` | toujours | Rappel de ce qui est facturé. |
| `created_at` | toujours | RFC 3339. |
| `payment_url` | `paylink` | Page où rediriger le client. |
| `action_hint` | si le client doit agir | Ex. code USSD à composer. |
| `failure_reason` | échec immédiat | Raison de l'échec. |
| `callback_secret` | si `callback_url` fourni | Secret de signature des callbacks, **affiché une seule fois**. |

### 2.1 Paiement direct (USSD) — `POST /v1/payments/payin`

Le client reçoit une demande de validation sur son téléphone.

```bash
curl -s -X POST "$BASE_URL/v1/payments/payin" \
  -H "Authorization: Bearer $API_KEY" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  --data '{
    "channel": "mtn_momo",
    "amount": 100,
    "currency": "XAF",
    "reason": "Order #1234 — Premium plan",
    "customer_phone_number": "'"$PHONE"'",
    "customer_name": "Marie Dupont",
    "customer_email": "marie.dupont@example.com",
    "customer_lang": "fr",
    "callback_url": "https://example.com/webhooks/core"
  }'
```

`201` :

```json
{"id":"01a0d4aa-f70f-72e8-a1d0-34e726d0c551","status":"pending","is_final":false,"amount":100,"currency":"XAF","channel":"mtn_momo","created_at":"2026-10-03T11:58:42+00:00","action_hint":"#150*50#","callback_secret":"pcbsec_XXXXXXXX"}
```

À savoir :
- **Comptez ~15 à 20 secondes de latence** : MyCoolPay interroge l'opérateur avant de
  répondre. Réglez le timeout de votre client HTTP à 60 s au moins.
- `action_hint` : le code USSD à composer si la fenêtre de validation ne s'ouvre pas
  sur le téléphone du client. Absent quand le client n'a rien à faire.
- `customer_lang` : **uniquement `fr` ou `en`** (autre valeur → `422`).
- Si `callback_url` est fourni, la réponse contient aussi `callback_secret`
  (`pcbsec_…`), **affiché une seule fois** : stockez-le, il sert à vérifier la
  signature HMAC des notifications reçues ([webhooks.md §4](webhooks.md#4-vérifier-la-signature)).
- Le solde du payeur insuffisant ressort en `502 provider-error` (le message précis du
  fournisseur est journalisé côté Core, pas renvoyé).

### 2.2 Lien de paiement — `POST /v1/payments/paylink`

Renvoie une URL hébergée où le client paie ; exemple complet de l'ensemble des champs :

```bash
curl -s -X POST "$BASE_URL/v1/payments/paylink" \
  -H "Authorization: Bearer $API_KEY" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  --data '{
    "channel": "mtn_momo",
    "amount": 100,
    "currency": "XAF",
    "reason": "Order #1234 — Premium plan",
    "customer_phone_number": "'"$PHONE"'",
    "customer_name": "Marie Dupont",
    "customer_email": "marie.dupont@example.com",
    "customer_lang": "fr",
    "return_url": "https://example.com/payments/return",
    "wallet_recipient_external_ref": "wallet-customer-42",
    "callback_url": "https://example.com/webhooks/core"
  }'
```

`201` (réponse réelle, en moins d'une seconde) :

```json
{"id":"01a0d4ab-3f86-7212-9c6b-5dfa4a057ed0","status":"pending","is_final":false,"amount":100,"currency":"XAF","channel":"mtn_momo","created_at":"2026-10-03T11:58:42+00:00","payment_url":"https://my-coolpay.com/payment/checkout/d67e68be-ffaa-4094-8861-213eff012842","callback_secret":"pcbsec_XXXXXXXX"}
```

Redirigez le client vers `payment_url`. `wallet_recipient_external_ref` : le wallet
indiqué n'est crédité que si le paiement réussit — jamais automatiquement sans ce champ.

### 2.3 Route historique — `POST /v1/payments`

Conservée pour compatibilité ; préférez `/payin` et `/paylink`. Le champ `flow`
(`payin` ou `paylink`, `payin` par défaut) choisit le mode.

```bash
curl -s -X POST "$BASE_URL/v1/payments" \
  -H "Authorization: Bearer $API_KEY" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  --data '{"channel":"mtn_momo","amount":100,"currency":"XAF","reason":"Order legacy","flow":"paylink"}'
```

### 2.4 Lire un paiement — `GET /v1/payments/{id}`

```bash
curl -s "$BASE_URL/v1/payments/01a0d4aa-f70f-72e8-a1d0-34e726d0c551" \
  -H "Authorization: Bearer $API_KEY" -H "Accept: application/json"
```

`200` :

```json
{"id":"01a0d4aa-f70f-72e8-a1d0-34e726d0c551","status":"pending","is_final":false,"amount":100,"currency":"XAF","channel":"mtn_momo","created_at":"2026-10-03T11:58:42+00:00","updated_at":"2026-10-03T11:58:42+00:00","action_hint":"#150*50#"}
```

Une fois final :

```json
{"id":"01a0d4aa-f70f-72e8-a1d0-34e726d0c551","status":"failed","is_final":true,"amount":100,"currency":"XAF","channel":"mtn_momo","created_at":"2026-10-03T11:58:42+00:00","updated_at":"2026-10-03T12:00:10+00:00","failure_reason":"Insufficient balance"}
```

Statuts intermédiaires : `pending`, `processing`. Statuts finaux : `succeeded`,
`failed`, `expired`, `canceled`, `refunded` — détail et conduite à tenir dans
[webhooks.md §3.1](webhooks.md#31-statuts-dun-paiement). **Testez `is_final`**, pas
une liste de statuts. Lit l'état du Core (mis à jour par le webhook du fournisseur) —
pas d'appel direct au fournisseur ; ne l'interrogez pas en boucle, attendez
l'événement. Paiement inconnu, d'un autre Project ou d'un autre environnement →
`404 payment-not-found`.

### 2.5 Erreurs de paiement vérifiées

| Cas | Réponse |
| --- | --- |
| Pas d'en-tête `Idempotency-Key` | `400 idempotency-key-required` |
| Rejeu de la même clé et du même corps | `201`, **même `id`**, aucun second débit |
| Devise inconnue (`"currency":"ZZZ"`) | `422 unsupported-currency` |
| `customer_lang` autre que `fr`/`en` | `422 validation-failed` (`errors.customer_lang`) |
| Application MyCoolPay non enregistrée (live) | `422` |
| Simulation d'un paiement déjà final | `409 payment-not-simulable` |
| Erreur du fournisseur (solde insuffisant, etc.) | `502 provider-error` |

## 3. Wallet — `GET /v1/wallets/{walletId}/balance`

Un wallet n'existe qu'après un premier crédit (paiement réussi avec
`wallet_recipient_external_ref`). `walletId` est l'identifiant du wallet.

```bash
curl -s "$BASE_URL/v1/wallets/01a0d4ae-bb83-73d9-8e22-d9cd9b5e18d8/balance" \
  -H "Authorization: Bearer $API_KEY" -H "Accept: application/json"
```

```json
{"wallet_id":"01a0d4ae-bb83-73d9-8e22-d9cd9b5e18d8","currency":"XAF","available_minor":100,"held_minor":0}
```

Wallet inconnu ou d'un autre Project → `404 wallet-not-found`.

## 4. Webhooks sortants

Réception, signature, nouvelles tentatives et catalogue des événements :
**[webhooks.md](webhooks.md)**. Un endpoint appartient à l'environnement de la clé qui
l'enregistre.

### 4.1 Enregistrer — `POST /v1/webhook-endpoints`

```bash
curl -s -X POST "$BASE_URL/v1/webhook-endpoints" \
  -H "Authorization: Bearer $API_KEY" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  --data '{"url":"https://example.com/webhooks/core","events":["payment.status_changed"]}'
```

`201` — le `secret` (`whsec_…`) **n'est montré qu'une fois** :

```json
{"id":"01a0d4ab-94f0-72ca-ab5e-497285832ca0","url":"https://example.com/webhooks/core","events":["payment.status_changed"],"secret":"whsec_XXXXXXXX"}
```

Événements acceptés : `*`, `payment.status_changed`, `notification.delivered`,
`notification.read`, `notification.failed`, `wallet.credited`, `wallet.debited`.
L'URL doit être publique et **résoudre en DNS** : `localhost`, IP privées ou
`169.254.169.254` sont refusées (`422`, `errors.url`), comme un hôte inexistant.

### 4.2 Lister — `GET /v1/webhook-endpoints`

```bash
curl -s "$BASE_URL/v1/webhook-endpoints" \
  -H "Authorization: Bearer $API_KEY" -H "Accept: application/json"
```

```json
{"data":[{"id":"01a0d4ab-94f0-72ca-ab5e-497285832ca0","url":"https://example.com/webhooks/core","events":["payment.status_changed"],"active":true,"environment":"sandbox"}]}
```

### 4.2 bis Tester — `POST /v1/webhook-endpoints/{id}/test`

Envoie tout de suite un événement `webhook.test` signé et renvoie la réponse de votre
serveur — de quoi valider votre vérification de signature avant le premier paiement :

```bash
curl -s -X POST "$BASE_URL/v1/webhook-endpoints/01a0d4ab-94f0-72ca-ab5e-497285832ca0/test" \
  -H "Authorization: Bearer $API_KEY" -H "Accept: application/json"
```

```json
{"delivered":true,"event_id":"01a101a1-7b6c-7069-b65d-31d61b74b5a8","response_status":200,"response_body":"ok","error":null,"duration_ms":16}
```

### 4.3 Désactiver — `DELETE /v1/webhook-endpoints/{id}`

```bash
curl -s -X DELETE "$BASE_URL/v1/webhook-endpoints/01a0d4ab-94f0-72ca-ab5e-497285832ca0" \
  -H "Authorization: Bearer $API_KEY" -H "Accept: application/json" -w "\nHTTP %{http_code}\n"
```

`204`, sans corps.

## 5. Notifications WhatsApp

**Les sessions WhatsApp sont créées et liées par l'administrateur de la plateforme**, jamais
par le client. Demandez-lui une session pour votre projet : il vous donne son nom (par
exemple `ma-boutique`), à passer dans `variables.session`. Une session peut aussi être
**globale** (partagée par tous les projets). Si le nom n'est pas enregistré pour votre
projet, la requête est refusée en `422` avec un message explicite et **rien n'est envoyé**.

Le numéro du destinataire se passe dans **`variables.phone_number`** (format E.164) ;
`to_party_ref` n'est qu'une référence de corrélation de votre côté, jamais un contact.
`client_reference` (optionnel) déduplique : la même valeur sur le même Project renvoie
la notification d'origine sans second envoi.

> Vérifié en réel (2026-09-24) avec une session enregistrée : les trois envois
> ci-dessous répondent `201` `sent` et le message arrive sur le téléphone.

### 5.1 Texte — `POST /v1/notifications/text`

```bash
curl -s -X POST "$BASE_URL/v1/notifications/text" \
  -H "Authorization: Bearer $API_KEY" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  --data '{
    "channel": "whatsapp",
    "to_party_ref": "customer-42",
    "text": "Hello Marie, your order #1234 is confirmed.",
    "variables": { "phone_number": "'"$PHONE"'", "session": "'"$SESSION"'" },
    "client_reference": "client-msg-abc123"
  }'
```

`201` (la notification a été remise au fournisseur) :

```json
{"id":"01a0d548-f3c1-729b-b36b-b66ae2155b27","channel":"whatsapp","status":"sent","content_type":"text","error_message":null}
```

### 5.2 Image — `POST /v1/notifications/image`

`media_url` doit être une URL **publique qui existe réellement** (elle est vérifiée :
hôte inexistant → `422`) et **directement téléchargeable** : certains hébergeurs
refusent les téléchargements automatiques (`403`), l'envoi échoue alors côté WhatsApp.

```bash
curl -s -X POST "$BASE_URL/v1/notifications/image" \
  -H "Authorization: Bearer $API_KEY" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  --data '{
    "channel": "whatsapp",
    "to_party_ref": "customer-42",
    "media_url": "https://upload.wikimedia.org/wikipedia/commons/4/47/PNG_transparency_demonstration_1.png",
    "caption": "Your order confirmation",
    "filename": "order-1234.png",
    "mimetype": "image/png",
    "variables": { "phone_number": "'"$PHONE"'", "session": "'"$SESSION"'" },
    "client_reference": "img-1"
  }'
```

```json
{"id":"01a0d548-fcdd-71ae-8e60-e68b99b7cce0","channel":"whatsapp","status":"sent","content_type":"image","error_message":null}
```

### 5.3 Fichier — `POST /v1/notifications/file`

```bash
curl -s -X POST "$BASE_URL/v1/notifications/file" \
  -H "Authorization: Bearer $API_KEY" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  --data '{
    "channel": "whatsapp",
    "to_party_ref": "customer-42",
    "media_url": "https://www.orimi.com/pdf-test.pdf",
    "filename": "invoice-1234.pdf",
    "mimetype": "application/pdf",
    "caption": "Your invoice for order #1234",
    "variables": { "phone_number": "'"$PHONE"'", "session": "'"$SESSION"'" },
    "client_reference": "file-1"
  }'
```

```json
{"id":"01a0d54a-0c70-71ec-b32d-542c08333bab","channel":"whatsapp","status":"sent","content_type":"file","error_message":null}
```

### 5.4 Suivre une notification — `GET /v1/notifications/{id}`

```bash
curl -s "$BASE_URL/v1/notifications/01a0d548-f3c1-729b-b36b-b66ae2155b27" \
  -H "Authorization: Bearer $API_KEY" -H "Accept: application/json"
```

```json
{"id":"01a0d548-f3c1-729b-b36b-b66ae2155b27","channel":"whatsapp","status":"sent","content_type":"text","error_message":null}
```

### 5.5 Erreurs de notification vérifiées

| Cas | Réponse |
| --- | --- |
| Canal WhatsApp sans `variables.phone_number` | `422` (`errors["variables.phone_number"]`) |
| Canal WhatsApp sans `variables.session`, ou nom invalide (autre chose que lettres, chiffres, `-`, `_`, 64 max) | `422` (`errors["variables.session"]`) |
| Session non enregistrée pour votre projet (et non globale) | `422` `channel-session-not-found`, réponse en quelques dizaines de ms, **rien n'est envoyé** (exemple ci-dessous) |
| Session enregistrée mais aucun téléphone lié (ou déconnecté) | `201` avec `"status":"failed"` et un `error_message` explicite : « The WhatsApp session used for this send is not linked to a phone (…). Ask the platform administrator to link it… Nothing was sent. » |
| `channel` autre que `whatsapp` | `422` (`errors.channel`) |
| `media_url` inexistante / non publique | `422` (`errors.media_url`) |
| Échec côté WhatsApp/WAHA (session déconnectée…) | `201` avec `"status":"failed"` et `error_message` — l'envoi est traité de façon synchrone, **testez toujours `status`**, pas seulement le code HTTP |

Réponse réelle pour une session non enregistrée (`422`) :

```json
{"type":"https://errors.core-system.internal/channel-session-not-found","title":"WhatsApp session not found","status":422,"detail":"The WhatsApp session \"inconnue\" is not registered for this project. Sessions are created by the platform administrator: ask for it to be created and linked, then retry. Nothing was sent.","correlation_id":"ef75f18c-c31a-4883-83a0-8784f62cf60b"}
```

## 6. Session WhatsApp : liaison d'un téléphone

La **création** d'une session se fait dans la console d'administration (Sessions WhatsApp,
sur la fiche du projet ou au niveau plateforme) : elle démarre la session chez le
fournisseur et l'enregistre. Ce n'est pas un appel d'API client.

Une fois la session créée, ces endpoints servent à **lier le téléphone** ; ils ne
fonctionnent que pour une session déjà enregistrée pour votre projet (sinon `422`
explicite, et jamais de création implicite) :

```bash
# QR code à scanner (PNG en base64 dans "data")
curl -s "$BASE_URL/v1/notification-channel-sessions/whatsapp/$SESSION/qr" \
  -H "Authorization: Bearer $API_KEY" -H "Accept: application/json"

# Capture d'écran de la session
curl -s "$BASE_URL/v1/notification-channel-sessions/whatsapp/$SESSION/screenshot" \
  -H "Authorization: Bearer $API_KEY" -H "Accept: application/json"

# Code d'appairage à saisir dans WhatsApp > Appareils connectés
curl -s -X POST "$BASE_URL/v1/notification-channel-sessions/whatsapp/$SESSION/pairing-code" \
  -H "Authorization: Bearer $API_KEY" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  --data '{"phone_number":"'"$PHONE"'"}'
```

QR et capture : `200` avec `{"mime_type":"image/png","data":"<base64>"}`.
Pairing-code : `200` avec `{"code":"2692-4822"}`. Session inconnue de votre projet (ou
session globale) : `422` `channel-session-not-found`, comme ci-dessus. Le pairing-code déclenche une
notification de liaison sur le numéro fourni : n'utilisez que le vôtre.

## 7. Récapitulatif

| Endpoint | Testé en réel | Résultat |
| --- | --- | --- |
| `GET /v1/whoami` | oui | 200 |
| `POST /v1/payments/payin` | oui (MyCoolPay) | 201, ~17 s |
| `POST /v1/payments/paylink` | oui (MyCoolPay) | 201 |
| `POST /v1/payments` | oui (MyCoolPay) | 201 |
| `GET /v1/payments/{id}` | oui | 200 / 404 |
| `GET /v1/wallets/{id}/balance` | oui (wallet crédité en interne) | 200 / 404 |
| `POST/GET/DELETE /v1/webhook-endpoints` | oui | 201 / 200 / 204 |
| `POST /v1/webhook-endpoints/{id}/test` | tests d'intégration | 200 |
| `POST /v1/sandbox/payments/{id}/simulate` | tests d'intégration | 200 / 409 / 403 |
| `POST /v1/notifications/text` | oui (WAHA) | 201 `sent` |
| `POST /v1/notifications/image` | oui (WAHA) | 201 `sent` |
| `POST /v1/notifications/file` | oui (WAHA) | 201 `sent` |
| `GET /v1/notifications/{id}` | oui | 200 |
| `GET …/qr`, `GET …/screenshot`, `POST …/pairing-code` | oui (WAHA) | 200 / 422 si non enregistrée |

Le passage d'un paiement à un statut final, l'événement qui en découle et le crédit du
wallet indiqué sont couverts de bout en bout en sandbox (simulateur) par la suite de
tests ; en live, ils dépendent de la validation par le client sur son téléphone.
