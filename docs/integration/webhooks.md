# Recevoir les événements du Core — webhooks et `callback_url`

Ce document dit tout ce qu'il faut pour **recevoir** un événement du Core, le
**vérifier** et le **traiter une seule fois**. Les exemples de code de la §4 sont
exécutés par la suite de tests contre une vraie requête signée par le Core
(`tests/Integration/WebhookTestAndSignatureExamplesTest.php`) : s'ils cessaient de
fonctionner, le build échouerait.

## 1. Deux façons de recevoir un événement

| | Endpoint webhook | `callback_url` d'un paiement |
| --- | --- | --- |
| Déclaration | `POST /v1/webhook-endpoints` (une fois par projet) | champ `callback_url` à la création du paiement |
| Événements | ceux auxquels l'endpoint est abonné (§3) | `payment.status_changed` de **ce** paiement seulement |
| Secret de signature | `secret` (`whsec_…`) renvoyé **une seule fois** à l'enregistrement | `callback_secret` (`pcbsec_…`) renvoyé **une seule fois** à la création du paiement |
| Corps | enveloppe `{id, type, data}` | **la même** enveloppe, octet pour octet |
| Nouvelles tentatives | 8 tentatives : 30 s, 90 s, 4 min 30, 13 min 30, 40 min 30, 2 h, 6 h (backoff ×3, plafonné à 6 h) | 8 tentatives : 30 s, 1 min, 2 min, 5 min, 15 min, 30 min, 1 h |
| Après épuisement | file des lettres mortes (DLQ), rejouable par l'opérateur | abandon (le paiement reste lisible par `GET /v1/payments/{id}`) |

Un paiement créé **avec** `callback_url` est notifié à cette URL ; il n'est **pas**
relayé en plus vers vos endpoints webhook. Sans `callback_url`, ses changements de
statut partent vers vos endpoints abonnés à `payment.status_changed`.

Le staging et la production du Core sont deux déploiements distincts : un endpoint
enregistré sur le staging ne reçoit que les événements du staging.

## 2. Ce que le Core envoie

```http
POST /webhooks/core HTTP/1.1
Content-Type: application/json
X-Event-Id: 01a101a1-7b88-7275-962b-935f19a9126d
X-Event-Type: payment.status_changed
X-Delivery-Id: 01a101a1-7b6c-7069-b65d-31d61bef74c3
X-Timestamp: 1791028722
X-Signature: b85d564832d9097e52ace32608a2d463aa87279b88020073afa98d922ccf4766

{"id":"01a101a1-7b88-7275-962b-935f19a9126d","type":"payment.status_changed","data":{"payment_id":"01a101a1-7b14-70de-874c-0e4586b565d0","reference":"d922d9a7-3226-457c-be8c-e4fabf850b2c","status":"failed","is_final":true,"amount":100,"currency":"XAF","failure_reason":"Insufficient balance","occurred_at":"2026-10-03T11:58:42Z"}}
```

| En-tête | Signification |
| --- | --- |
| `X-Event-Id` | Identifiant unique de l'événement, identique à `id` dans le corps. **Clé de déduplication.** |
| `X-Event-Type` | Identique à `type` dans le corps. |
| `X-Delivery-Id` | Identifiant de cette livraison (webhooks seulement). Un rejeu par l'opérateur en crée un nouveau, avec le **même** `X-Event-Id`. À citer au support. |
| `X-Timestamp` | Heure d'envoi, secondes Unix. Signée. |
| `X-Signature` | HMAC-SHA256 hexadécimal (minuscules) de `X-Timestamp + "." + corps brut`, avec votre secret. |

Votre endpoint doit répondre **`2xx` en moins de 10 secondes**. Le corps de votre
réponse est ignoré (il est seulement conservé pour le diagnostic, tronqué à 4 Ko).

| Votre réponse | Ce que fait le Core |
| --- | --- |
| `2xx` | livré, terminé |
| `408`, `429`, `5xx`, délai dépassé, connexion refusée | nouvelle tentative selon le calendrier de la §1 |
| autre `4xx` (`400`, `401`, `404`…) | **échec définitif immédiat**, sans nouvelle tentative (webhooks : DLQ) |

Une réponse `4xx` signifie « ce message ne sera jamais accepté » : ne renvoyez `401`
que pour une signature invalide, jamais pour une erreur temporaire de votre côté
(renvoyez alors `503`).

## 3. Catalogue des événements

Champ `data` de l'enveloppe, par type. Le schéma complet est publié dans `/docs/api`
(schémas `WebhookEvent`, `WebhookEventType`, `PaymentCallbackEvent`).

### `payment.status_changed`

| Champ | Type | Description |
| --- | --- | --- |
| `payment_id` | string | Identifiant du paiement (celui renvoyé à la création). |
| `reference` | string | Votre `Idempotency-Key` de création : retrouvez votre commande avec. |
| `status` | string | Nouveau statut (§3.1). |
| `is_final` | bool | `true` : le paiement ne changera plus (hors `refunded`). |
| `amount` | int | Montant en plus petite unité de la devise. |
| `currency` | string | Code ISO 4217. |
| `failure_reason` | string \| null | Raison de l'échec, si `status` n'est pas `succeeded`. |
| `occurred_at` | string | Date du changement, RFC 3339 UTC. |

#### 3.1 Statuts d'un paiement

| Statut | Final ? | Que faire |
| --- | --- | --- |
| `pending` | non | Attendre. Le client n'a pas encore validé. |
| `processing` | non | Attendre. L'opérateur traite le paiement. |
| `succeeded` | **oui** | Livrer la commande. |
| `failed` | **oui** | Proposer un nouveau paiement (lire `failure_reason`). |
| `expired` | **oui** | Le client n'a pas validé à temps : proposer un nouveau paiement. |
| `canceled` | **oui** | Le client a annulé. |
| `refunded` | **oui** | Remboursé après un `succeeded` — seule transition possible après un statut final. |

Le Core n'applique jamais une transition arrière (un `pending` reçu en retard après
`processing` est ignoré) : vous ne recevrez pas un statut intermédiaire après un statut
final. Fiez-vous à `is_final` plutôt qu'à une liste de statuts codée en dur.

### `notification.delivered`, `notification.read`, `notification.failed`

| Champ | Description |
| --- | --- |
| `notification_id` | Identifiant renvoyé par `POST /v1/notifications/*`. |
| `channel` | `whatsapp`. |
| `status` | `delivered`, `read` ou `failed`. |
| `reason` | Raison de l'échec (`failed`), sinon `null`. |

### `wallet.credited`, `wallet.debited`

Un événement par wallet dont le solde change : un transfert de plusieurs jambes
en émet un par wallet touché, avec le mouvement net de ce wallet. Les champs
facultatifs ne sont présents que s'ils sont connus.

| Champ | Description |
| --- | --- |
| `wallet_id` | Identifiant du wallet. |
| `party_id` | Identifiant interne du tiers propriétaire du wallet. |
| `party_external_ref` | Votre référence du tiers (facultatif). |
| `amount_minor` | Montant crédité ou débité, en plus petite unité. |
| `currency` | Code ISO 4217. |
| `operation_type` | `payment`, `transfer` ou `withdrawal`. |
| `payment_id` | Le paiement qui a crédité le wallet (`payment`). |
| `operation_id` | L'opération (`transfer`, `withdrawal`). |
| `reference` | Votre `Idempotency-Key` de l'opération (`transfer`, `withdrawal`). |
| `description` | Votre libellé de l'opération, s'il a été fourni. |


### `webhook.test`

Envoyé **uniquement** à la demande, par `POST /v1/webhook-endpoints/{id}/test`
(§5). Même enveloppe, mêmes en-têtes, même signature qu'un vrai événement. Il ne
peut pas faire l'objet d'un abonnement et n'est jamais renvoyé.

## 4. Vérifier la signature

Trois règles, quel que soit le langage :

1. **Signez le corps brut**, tel que reçu, **avant** tout décodage JSON. Un
   `json_decode` puis `json_encode` change l'ordre ou l'échappement des caractères,
   et la signature ne correspond plus.
2. **Rejetez un `X-Timestamp` trop ancien** (5 minutes) : sans cela, un message
   intercepté pourrait être rejoué indéfiniment.
3. **Comparez en temps constant** (`hash_equals`, `crypto.timingSafeEqual`,
   `hmac.compare_digest`), jamais avec `==`.

### PHP

Fichier complet : [`examples/verify-signature.php`](examples/verify-signature.php).

```php
function verifyCoreSignature(string $secret, string $rawBody, string $timestamp, string $signature, int $toleranceSeconds = 300): bool
{
    if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > $toleranceSeconds) {
        return false;
    }

    $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

    return hash_equals($expected, $signature);
}

// Laravel :
$ok = verifyCoreSignature(
    config('services.core.webhook_secret'),
    $request->getContent(),                      // corps BRUT
    (string) $request->header('X-Timestamp'),
    (string) $request->header('X-Signature'),
);
```

### Node.js

Fichier complet : [`examples/verify-signature.js`](examples/verify-signature.js).

```js
const crypto = require('crypto');

function verifyCoreSignature(secret, rawBody, timestamp, signature, toleranceSeconds = 300) {
  if (!/^\d+$/.test(timestamp || '')) return false;
  if (Math.abs(Math.floor(Date.now() / 1000) - Number(timestamp)) > toleranceSeconds) return false;

  const expected = crypto.createHmac('sha256', secret).update(`${timestamp}.${rawBody}`).digest('hex');
  const a = Buffer.from(expected, 'utf8');
  const b = Buffer.from(signature || '', 'utf8');
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}

// Express : le corps BRUT, pas express.json()
app.post('/webhooks/core', express.raw({ type: 'application/json' }), (req, res) => {
  const raw = req.body.toString('utf8');
  if (!verifyCoreSignature(process.env.CORE_WEBHOOK_SECRET, raw, req.get('X-Timestamp'), req.get('X-Signature'))) {
    return res.status(401).end();
  }
  const event = JSON.parse(raw);
  // … traiter event (voir §6), puis :
  res.status(200).end();
});
```

### Python

Fichier complet : [`examples/verify_signature.py`](examples/verify_signature.py).

```python
import hashlib, hmac, time

def verify_core_signature(secret: str, raw_body: str, timestamp: str, signature: str, tolerance_seconds: int = 300) -> bool:
    if not timestamp.isdigit() or abs(int(time.time()) - int(timestamp)) > tolerance_seconds:
        return False
    expected = hmac.new(secret.encode(), f"{timestamp}.{raw_body}".encode(), hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, signature)

# Flask :
@app.post("/webhooks/core")
def core_webhook():
    raw = request.get_data(as_text=True)          # corps BRUT
    if not verify_core_signature(CORE_WEBHOOK_SECRET, raw, request.headers.get("X-Timestamp", ""), request.headers.get("X-Signature", "")):
        return "", 401
    event = json.loads(raw)
    # … traiter event (voir §6)
    return "", 200
```

### Vérifier à la main

```bash
SECRET="whsec_…"; TS="1791028722"; BODY='{"id":"…","type":"…","data":{…}}'
printf '%s' "$TS.$BODY" | openssl dgst -sha256 -hmac "$SECRET"
# doit afficher la même valeur que l'en-tête X-Signature
```

## 5. Tester son endpoint — `POST /v1/webhook-endpoints/{id}/test`

Envoie immédiatement un événement `webhook.test` signé à l'endpoint et renvoie ce que
votre serveur a répondu. Aucun nouvel essai, rien n'est enregistré comme livraison.
Limité à 10 appels par minute.

```bash
curl -s -X POST "$BASE_URL/v1/webhook-endpoints/01a101a1-7b5c-7374-808b-2d1c2b3b7121/test" \
  -H "Authorization: Bearer $API_KEY" -H "Accept: application/json"
```

```json
{"delivered":true,"event_id":"01a101a1-7b6c-7069-b65d-31d61b74b5a8","response_status":200,"response_body":"ok","error":null,"duration_ms":16}
```

- `delivered: false` avec `response_status: 401` : votre vérification de signature
  rejette le message. Vérifiez le secret et que vous signez bien le corps **brut**.
- `delivered: false` avec `response_status: null` : votre URL est injoignable (DNS,
  pare-feu, certificat TLS) ; `error` donne le détail.

## 6. Traiter un événement correctement

1. **Vérifiez la signature** (§4). Invalide → `401`.
2. **Dédupliquez sur `id`** : un même événement peut arriver plusieurs fois (votre
   `2xx` perdu sur le réseau, rejeu par l'opérateur). Enregistrez les `id` traités ;
   s'il est déjà connu, répondez `200` sans rien refaire.
3. **Répondez vite** : enregistrez l'événement, répondez `200`, puis traitez-le en
   arrière-plan si le traitement est long. Au-delà de 10 s, le Core considère l'envoi
   comme échoué et renvoie l'événement.
4. **Ne supposez pas l'ordre** : deux événements peuvent arriver dans le désordre.
   Pour un paiement, ignorez un événement dont `occurred_at` est antérieur au dernier
   que vous avez appliqué, et ne quittez jamais un statut où `is_final` vaut `true`
   (sauf vers `refunded`).
5. **En cas de doute, relisez** : `GET /v1/payments/{payment_id}` donne l'état de
   référence, à tout moment.

## 7. Si un événement n'arrive pas

- L'opérateur voit chaque livraison, chaque tentative, la requête exacte envoyée et
  votre réponse dans la console (`Webhooks → Livraisons`, et dans le « Parcours » de
  chaque paiement). Donnez-lui l'`X-Delivery-Id` ou l'identifiant du paiement.
- Une livraison en DLQ peut être rejouée par l'opérateur une fois votre endpoint
  corrigé : vous recevez alors le **même** `X-Event-Id` — d'où la déduplication.
- Un endpoint désactivé (`DELETE /v1/webhook-endpoints/{id}`) ne reçoit plus rien ;
  les livraisons qui lui étaient destinées sont annulées.
