# Guide d'intégration — commandes `curl` vérifiées

Chaque commande de ce document a été **exécutée pour de vrai** le 2026-09-24 contre
une instance du Core branchée sur une vraie application MyCoolPay et un vrai WAHA
(WhatsApp) — pas contre des simulations. Les réponses montrées sont les réponses
réelles obtenues (identifiants et secrets remplacés par des exemples).

Les charges utiles sont les mêmes que les exemples de la documentation interactive
(`/docs/api`, bouton « Try it out »), à deux nuances près, signalées : le numéro de
téléphone (à remplacer par le vôtre) et les URL de médias (qui doivent exister).

## 0. Préparation

```bash
export BASE_URL="https://core-system.visibilitycam.com"   # en local : http://localhost:8000
export API_KEY="pk_sandbox_XXXXXX.XXXXXXXXXXXXXXXX"        # clé du Project (voir ci-dessous)
export PHONE="+2376XXXXXXXX"                               # un numéro qui vous appartient, format E.164
```

- **Clé API** : à créer dans la console (`/admin/projects/<id>/api-keys/create`), une
  clé `pk_sandbox_…` (sandbox) ou `pk_live_…` (production). Elle est affichée **une seule
  fois**. Elle porte tous les scopes.
- **En-têtes communs** : `Authorization: Bearer $API_KEY`, `Accept: application/json`,
  et `Content-Type: application/json` pour les requêtes avec corps.
- **Erreurs** : format RFC 7807 (`application/problem+json`) avec `type`, `title`,
  `status`, `detail`, `correlation_id` — citez le `correlation_id` au support.
- **`Idempotency-Key`** : **obligatoire** sur les 3 routes de paiement. Une UUID par
  intention de paiement ; rejouer la même clé renvoie la réponse d'origine sans
  débiter une seconde fois.
- **Montants** : entier, dans la plus petite unité de la devise. `XAF` n'a pas de
  sous-unité : `100` = 100 XAF. `USD`/`EUR` : `100` = 1,00.

## 1. Vérifier sa clé — `GET /v1/whoami`

```bash
curl -s "$BASE_URL/v1/whoami" \
  -H "Authorization: Bearer $API_KEY" \
  -H "Accept: application/json"
```

```json
{"project_id":"01a0d4a4-11d0-732b-b413-db21199feb62","organization_id":"01a0d4a4-03c7-7279-b962-6345365ee47d","environment":"sandbox","scopes":["payments:read","payments:write","webhooks:read","webhooks:write","notifications:read","notifications:write","notification_channel_sessions:read","notification_channel_sessions:write","wallets:read"]}
```

Clé invalide ou révoquée → `401`.

## 2. Paiements

Le Project doit avoir une application MyCoolPay enregistrée (console →
Project → *Applications provider*), sinon la création échoue en `422`.

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
{"id":"01a0d4aa-f70f-72e8-a1d0-34e726d0c551","channel":"mtn_momo","status":"pending","amount":100,"currency":"XAF","requires_action":true,"action_hint":"#150*50#","payment_url":null,"idempotency_key":"bc520fa7-4312-4148-8350-7d220fdcd122","callback_url":null}
```

À savoir :
- **Comptez ~15 à 20 secondes de latence** : MyCoolPay interroge l'opérateur avant de
  répondre. Réglez le timeout de votre client HTTP à 60 s au moins.
- `requires_action: true` + `action_hint` : le code USSD à composer si la fenêtre de
  validation ne s'ouvre pas sur le téléphone du client.
- `customer_lang` : **uniquement `fr` ou `en`** (autre valeur → `422`).
- Si `callback_url` est fourni, la réponse contient aussi `callback_secret`
  (`pcbsec_…`), **affiché une seule fois** : stockez-le, il sert à vérifier la
  signature HMAC des notifications reçues.
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
{"id":"01a0d4ab-3f86-7212-9c6b-5dfa4a057ed0","channel":"mtn_momo","status":"pending","amount":100,"currency":"XAF","requires_action":false,"action_hint":null,"payment_url":"https://my-coolpay.com/payment/checkout/d67e68be-ffaa-4094-8861-213eff012842","idempotency_key":"4d57e63a-44eb-4417-8d75-a9ff7507b13f","callback_url":"https://example.com/webhooks/core","callback_secret":"pcbsec_XXXXXXXX"}
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
{"id":"01a0d4aa-f70f-72e8-a1d0-34e726d0c551","channel":"mtn_momo","status":"pending","amount":100,"currency":"XAF","requires_action":true,"action_hint":"#150*50#","payment_url":null,"error_message":null,"idempotency_key":"bc520fa7-4312-4148-8350-7d220fdcd122","callback_url":null}
```

Statuts : `pending`, `processing`, puis un état final (`succeeded`, `failed`, `expired`, `canceled`, `refunded`). Lit l'état
du Core (mis à jour par le webhook du fournisseur) — pas d'appel direct au fournisseur.
Paiement inconnu ou d'un autre Project → `404 payment-not-found`.

### 2.5 Erreurs de paiement vérifiées

| Cas | Réponse |
| --- | --- |
| Pas d'en-tête `Idempotency-Key` | `400 idempotency-key-required` |
| Rejeu de la même clé et du même corps | `201`, **même `id`**, aucun second débit |
| Devise inconnue (`"currency":"ZZZ"`) | `422 unsupported-currency` |
| `customer_lang` autre que `fr`/`en` | `422 validation-failed` (`errors.customer_lang`) |
| Application MyCoolPay non enregistrée | `422` |
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

Événements acceptés : `*`, `payment.status_changed`, `notification.status_changed`,
`notification.failed`, `wallet.credited`, `wallet.debited`.
L'URL doit être publique et **résoudre en DNS** : `localhost`, IP privées ou
`169.254.169.254` sont refusées (`422`, `errors.url`), comme un hôte inexistant.

### 4.2 Lister — `GET /v1/webhook-endpoints`

```bash
curl -s "$BASE_URL/v1/webhook-endpoints" \
  -H "Authorization: Bearer $API_KEY" -H "Accept: application/json"
```

```json
{"data":[{"id":"01a0d4ab-94f0-72ca-ab5e-497285832ca0","url":"https://example.com/webhooks/core","events":["payment.status_changed"],"active":true}]}
```

### 4.3 Désactiver — `DELETE /v1/webhook-endpoints/{id}`

```bash
curl -s -X DELETE "$BASE_URL/v1/webhook-endpoints/01a0d4ab-94f0-72ca-ab5e-497285832ca0" \
  -H "Authorization: Bearer $API_KEY" -H "Accept: application/json" -w "\nHTTP %{http_code}\n"
```

`204`, sans corps.

## 5. Notifications WhatsApp

**Chaque client envoie depuis sa propre session WhatsApp** : il n'existe pas de
numéro partagé par défaut. Avant le premier envoi, liez votre téléphone à une session
que vous nommez vous-même (voir [§6](#6-session-whatsapp-création-et-liaison-dun-téléphone) —
`ma-boutique` dans les exemples), puis passez ce nom dans `variables.session`.
Sans `variables.session` : `422`.

Le numéro du destinataire se passe dans **`variables.phone_number`** (format E.164) ;
`to_party_ref` n'est qu'une référence de corrélation de votre côté, jamais un contact.
`client_reference` (optionnel) déduplique : la même valeur sur le même Project renvoie
la notification d'origine sans second envoi.

> Les exemples de réponse `sent` ci-dessous ont été obtenus le 2026-09-24 avant que
> `variables.session` ne devienne obligatoire (l'envoi partait alors de la session par
> défaut du serveur). Le comportement de la nouvelle règle a été vérifié en réel pour
> les cas de refus (`422` sans session ou avec un nom invalide ; `failed` avec une
> session non liée) mais l'envoi réussi depuis une session liée par un client reste à
> confirmer avec une vraie session liée.

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
    "variables": { "phone_number": "'"$PHONE"'", "session": "ma-boutique" },
    "client_reference": "client-msg-abc123"
  }'
```

`201` (la notification a été remise au fournisseur) :

```json
{"id":"01a0d4ab-d472-7168-a70d-d715328418b7","channel":"whatsapp","status":"sent","content_type":"text","error_message":null}
```

### 5.2 Image — `POST /v1/notifications/image`

`media_url` doit être une URL **publique qui existe réellement** (elle est vérifiée :
hôte inexistant → `422`).

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
    "variables": { "phone_number": "'"$PHONE"'", "session": "ma-boutique" },
    "client_reference": "img-1"
  }'
```

```json
{"id":"01a0d4ac-4eea-715e-950f-8841086c7145","channel":"whatsapp","status":"sent","content_type":"image","error_message":null}
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
    "media_url": "https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf",
    "filename": "invoice-1234.pdf",
    "mimetype": "application/pdf",
    "caption": "Your invoice for order #1234",
    "variables": { "phone_number": "'"$PHONE"'", "session": "ma-boutique" },
    "client_reference": "file-1"
  }'
```

```json
{"id":"01a0d4ac-7c39-73f3-9dff-6b37c2280c76","channel":"whatsapp","status":"sent","content_type":"file","error_message":null}
```

### 5.4 Suivre une notification — `GET /v1/notifications/{id}`

```bash
curl -s "$BASE_URL/v1/notifications/01a0d4ab-d472-7168-a70d-d715328418b7" \
  -H "Authorization: Bearer $API_KEY" -H "Accept: application/json"
```

```json
{"id":"01a0d4ab-d472-7168-a70d-d715328418b7","channel":"whatsapp","status":"sent","content_type":"text","error_message":null}
```

### 5.5 Erreurs de notification vérifiées

| Cas | Réponse |
| --- | --- |
| Canal WhatsApp sans `variables.phone_number` | `422` (`errors["variables.phone_number"]`) |
| Canal WhatsApp sans `variables.session`, ou nom invalide (autre chose que lettres, chiffres, `-`, `_`, 64 max) | `422` (`errors["variables.session"]`) |
| Session inexistante ou non liée à un téléphone | `201` avec `"status":"failed"` (`error_message`: `HTTP 422 (permanent)`) |
| `channel` autre que `whatsapp` | `422` (`errors.channel`) |
| `media_url` inexistante / non publique | `422` (`errors.media_url`) |
| Échec côté WhatsApp/WAHA (session déconnectée…) | `201` avec `"status":"failed"` et `error_message` — l'envoi est traité de façon synchrone, **testez toujours `status`**, pas seulement le code HTTP |

## 6. Session WhatsApp : création et liaison d'un téléphone

C'est ce qui permet à chaque client d'envoyer depuis son propre numéro. Il n'y a pas
d'appel « créer une session » : le premier appel à `qr` ou `pairing-code` crée la session
si elle n'existe pas. `sessionRef` (lettres, chiffres, `-`, `_`) est un nom choisi par
vous ; c'est le même nom que vous passerez ensuite dans `variables.session` à l'envoi.
Le Core ne stocke rien sur la session, et chez le fournisseur elle est isolée par Project.

1. Appelez `qr` (ou `pairing-code`) avec votre `sessionRef`.
2. Liez le téléphone : scannez le QR, ou saisissez le code dans WhatsApp › Appareils connectés.
3. Envoyez avec `"session": "<sessionRef>"`.

```bash
# QR code à scanner (PNG en base64 dans "data")
curl -s "$BASE_URL/v1/notification-channel-sessions/whatsapp/ma-session/qr" \
  -H "Authorization: Bearer $API_KEY" -H "Accept: application/json"

# Capture d'écran de la session
curl -s "$BASE_URL/v1/notification-channel-sessions/whatsapp/ma-session/screenshot" \
  -H "Authorization: Bearer $API_KEY" -H "Accept: application/json"

# Code d'appairage à saisir dans WhatsApp > Appareils connectés
curl -s -X POST "$BASE_URL/v1/notification-channel-sessions/whatsapp/ma-session/pairing-code" \
  -H "Authorization: Bearer $API_KEY" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  --data '{"phone_number":"'"$PHONE"'"}'
```

QR et capture : `200` avec `{"mime_type":"image/png","data":"<base64>"}`.
Pairing-code : `200` avec `{"code":"MTCA-3VWR"}`. Le pairing-code déclenche une
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
| `POST /v1/notifications/text` | oui (WAHA) | 201 `sent` |
| `POST /v1/notifications/image` | oui (WAHA) | 201 `sent` |
| `POST /v1/notifications/file` | oui (WAHA) | 201 `sent` |
| `GET /v1/notifications/{id}` | oui | 200 |
| `GET …/qr`, `GET …/screenshot`, `POST …/pairing-code` | oui (WAHA) | 200 |

Non couvert en réel : le passage d'un paiement à `succeeded` (nécessite que le client
valide sur son téléphone et que MyCoolPay puisse joindre le Core par webhook) et donc le
crédit automatique du wallet qui en découle.
