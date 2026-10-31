# Brancher une application cliente

> Ce que fait un logiciel qui se branche au Core, et en combien de temps.
> Version de contrat : `/v1`. Décision d'architecture : [ADR-0107](../adr/0107-le-core-est-le-maitre-de-la-relation-commerciale.md).

## 0. Le principe, en une phrase

**Le Core est le maître de la relation commerciale ; votre application est le maître de
ses données métier.** Vous ne gérez ni offre, ni abonnement, ni facture, ni
encaissement : **le propriétaire de votre logiciel fait tout cela lui-même, dans le
portail que le Core héberge.**

Concrètement, votre application fait **cinq** choses :

| # | Ce qu'elle fait | Comment |
|---|---|---|
| 1 | **Déclarer** son locataire, une fois | `PUT /v1/customers/{ref}` |
| 2 | **Lire** ses droits | `GET /v1/customers/{ref}/entitlements` |
| 3 | **Compter** son usage | `POST /v1/usage` |
| 4 | **Ouvrir** le portail de son propriétaire | `POST /v1/portal/sessions` |
| 5 | **Recevoir** son état (abonnement, quotas, factures) | webhooks signés |

Cinq appels, dans un seul sens — et **aucun** droit d'écrire sur le commerce. Votre
clé ne portera pas `customers:write`, ni `subscriptions:write`, ni `invoices:write` :
ce n'est pas une promesse, c'est la portée de la clé.

> **Pourquoi ?** Le portail du Core possède déjà toute l'interface commerciale
> (`/portal/plans`, `subscribe`, `cancel`, `change-plan`, `renew`, `invoices`, `pay`).
> Réimplémenter cette gestion dans chaque application cliente serait la même
> erreur écrite dix fois — et l'erreur serait dans chaque application. Le Core la fait
> une fois, pour tous.

## 1. Préparation

```bash
export BASE_URL="https://core-system.visibilitycam.com"   # staging : l'adresse du staging du Core ; en local : http://localhost:8000
export API_KEY="pk_XXXXXXXX.XXXXXXXXXXXXXXXX"              # la clé de votre application (voir ci-dessous)
export TENANT="auto-ecole-42"                              # VOTRE référence de locataire : celle que VOUS connaissez
```

**La clé.** L'administrateur du Core vous en donne une, dans la console
(`/admin/projects/<id>/api-keys/create`), avec le modèle de portées
**« Application cliente »** :

```
customers:read · usage:read · usage:write · portal:write · subscriptions:read · invoices:read
```

Vérifiez-la — la réponse dit ce qu'elle est autorisée à faire :

```bash
curl -s "$BASE_URL/v1/whoami" \
  -H "Authorization: Bearer $API_KEY" \
  -H "Accept: application/json"
```

```json
{"project_id":"01a101a1-7a96-712d-b63a-e3bbc27ae721","organization_id":"01a101a1-7a88-7241-b0aa-99f174f09b01","scopes":["customers:read","usage:read","usage:write","portal:write","subscriptions:read","invoices:read"]}
```

Si votre clé porte `*` ou le modèle « Serveur complet », demandez une clé
« Application cliente » : elle est **plus sûre**, et elle ne vous empêche de rien.

## 2. Déclarer votre locataire — une fois, pour toute sa vie

Votre application a ses propres données métier (une auto-école a une adresse, un
responsable, une année de création) : le Core ne peut pas les inventer. Vous les
lui déclarez **une fois**, à la création du locataire, et vous n'y touchez plus.

La référence (`TENANT`) est **la vôtre** : une chaîne de 1 à 128 caractères, `[A-Za-z0-9._:-]`.
C'est elle que le Core vous rendra dans tous les événements.

```bash
curl -s -X PUT "$BASE_URL/v1/customers/$TENANT" \
  -H "Authorization: Bearer $API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "display_name": "Auto-École La Fontaine",
    "email": "contact@auto-ecole-la-fontaine.cm",
    "phone": "+237699887766",
    "locale": "fr"
  }'
```

`201` si le locataire est créé, `200` s'il existe déjà. **L'appel est idempotent** :
le rejouer ne crée pas de second locataire, il met à jour le sien. La réponse porte
un `ETag` (la version) ; si vous voulez vous protéger d'une écriture concurrente,
envoyez le `ETag` que vous avez lu en `If-Match` — sinon la réponse est `412`.

L'e-mail et le téléphone ne vous sont retournés que si votre clé porte
`customers:read` — la vôtre le porte.

> **Attention : cet appel est le seul qui crée quelque chose.** Tout le reste de
> votre intégration est en lecture ou en comptage.

## 3. Lire les droits du locataire

C'est la question que votre application pose à chaque page sensible : *« ce locataire
a-t-il le droit d'utiliser cette fonctionnalité ? »*.

```bash
curl -s "$BASE_URL/v1/customers/$TENANT/entitlements" \
  -H "Authorization: Bearer $API_KEY" \
  -H "Accept: application/json"
```

```json
{
  "customer_ref": "auto-ecole-42",
  "active": true,
  "plan_code": "pro",
  "subscription_id": "01a190b8-53be-70d1-9c46-a5b715b4ad80",
  "entitlements": [
    {
      "key": "students",
      "type": "limit",
      "enabled": true,
      "meter": "students",
      "limit": 50,
      "used": 12,
      "remaining": 38,
      "overage": "block",
      "threshold_bps": 8000,
      "window_start": "2026-10-01T00:00:00+00:00",
      "window_end": "2026-11-01T00:00:00+00:00"
    }
  ]
}
```

`active` vaut `false` quand l'abonnement est suspendu ou expiré : votre application
doit alors **refuser** l'accès — c'est à vous de décider comment (une page
d'explication, pas une page d'erreur 500).

> **Si vous préférez ne pas demander à chaque page** : mettez la réponse en cache
> pendant une minute, et rafraîchissez-la quand un événement `subscription.*` arrive
> (voir §6). Le paquet client fait cela pour vous.

## 4. Compter l'usage

Votre application **déclare** ce qu'elle a consommé ; le Core **compte** et **applique
la limite**. Vous n'avez pas de compteur à tenir.

```bash
curl -s -X POST "$BASE_URL/v1/usage" \
  -H "Authorization: Bearer $API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "customer_ref": "auto-ecole-42",
    "meter": "students",
    "quantity": 1,
    "idempotency_key": "inscription:STUDENT-2026-0042"
  }'
```

Trois points à ne pas manquer :

- **La clé d'idempotence est dans le corps**, sous le nom `idempotency_key` — et
  **seulement** là. C'est la seule route du Core où elle n'est pas un en-tête.
  Utilisez une valeur **stable** qui identifie *le fait* : `inscription:STUDENT-2026-0042`.
  Rejouer la même clé ne compte pas deux fois.
- **Une clé par fait, jamais par requête.** Deux inscriptions de deux élèves sont
  deux clés. Une même inscription réessayée est la même clé.
- Le compte est **fait dans la transaction qui crée le fait** de votre côté : appelez
  le Core quand l'inscription est enregistrée, pas avant, et pas après.

Réponses : `201` (compté), `200` (rejeu — déjà compté), `429` (`quota-exceeded`, rien
n'a été compté), `409` (le locataire n'a pas le droit d'utiliser ce compteur).

## 5. Ouvrir le portail de votre propriétaire

Votre propriétaire gère son abonnement, ses factures et ses paiements **dans le
portail**. Vous ne lui fabriquez pas un écran : vous lui donnez un lien.

```bash
curl -s -X POST "$BASE_URL/v1/portal/sessions" \
  -H "Authorization: Bearer $API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "customer_ref": "auto-ecole-42",
    "flow": "manage"
  }'
```

```json
{
  "url": "https://core-system.visibilitycam.com/portal/enter/7QwStnG15qHCvfR4Xfw7-RInTeDheEPUm6LiSKOqJmQ",
  "expires_at": "2026-10-31T12:10:00+00:00",
  "customer_created": false,
  "subscription_created": false
}
```

À vous de faire la redirection :

```php
return redirect($response['url']);
```

Le lien est **à usage unique** et **valable 10 minutes**. Le portail ouvre alors une
session d'une demi-heure, et votre propriétaire y trouve son offre, son abonnement,
ses factures, ses paiements, son usage et son profil.

Deux champs facultatifs :

- `flow` — `manage` (par défaut) ou `pay_invoice` ;
- `return_url` — où le renvoyer après son passage. **Vérifié avant toute écriture**
  contre la liste blanche du projet : une adresse hors liste donne `422`.

> **Ne transmettez pas `customer` ni `plan_code`.** Ces deux champs demandent des
> portées d'écriture (`customers:write`, `subscriptions:write`) que votre clé n'a
> pas, et le Core répond `403`. C'est **voulu** : le commerce ne se fait pas depuis
> votre application, il se fait dans le portail, par le propriétaire.

## 6. Recevoir l'état — les webhooks

Votre application doit savoir quand l'état de son locataire change : abonnement
activé, suspendu, expiré, facture émise, quota atteint.

**L'enregistrement se fait depuis le Core**, pas depuis votre application
(l'administrateur du Core enregistre votre point d'entrée et vous remet le secret).
Votre application se contente de **recevoir** et de **vérifier la signature** — la
procédure complète, avec le code de vérification dans les trois langages, est dans
**[webhooks.md](webhooks.md)**.

Les événements qui vous concernent :

| Événement | Ce que ça veut dire pour vous |
|---|---|
| `customer.created` | le locataire existe |
| `customer.updated` | son identité a changé |
| `customer.suspended` / `customer.reactivated` | il est bloqué / rétabli |
| `customer.erased` | il a été effacé définitivement |
| `subscription.created`, `subscription.activated` | l'abonnement démarre |
| `subscription.renewed`, `subscription.plan_changed` | l'offre a changé |
| `subscription.past_due`, `subscription.canceled`, `subscription.expired` | l'abonnement se termine |
| `subscription.trial_will_end`, `subscription.renewal_due` | à anticiper |
| `invoice.issued`, `invoice.paid`, `invoice.payment_failed`, `invoice.voided` | l'état d'une facture |
| `usage.threshold_reached`, `usage.limit_reached` | un quota est bientôt atteint / atteint |

Chaque charge utile porte un `version` : c'est le **verrou optimiste** de la
ressource. Rejouez un événement plus ancien que le dernier que vous avez traité, et
ignorez-le. Un événement livré deux fois ne doit être traité qu'une fois (l'`id` de
l'événement est votre clé de dédoublonnage).

Les événements `customer.*` et `subscription.*` ne portent **aucune donnée
personnelle** : le locataire y est nommé par votre propre référence. Si vous avez
besoin de son e-mail, vous le **demandez** (`GET /v1/customers/{ref}`).

## 7. Ce que votre propriétaire fait dans le portail

Pour que vous sachiez ce que vous n'avez pas à faire :

| Dans le portail | Ce que votre propriétaire y fait |
|---|---|
| `/portal/plans` | voit les offres que **vous** avez publiées dans le Core |
| `/portal/plans/{code}/subscribe` | choisit son offre et s'abonne |
| `/portal/subscriptions` | voit son abonnement, sa période, son renouvellement |
| `/portal/subscriptions/{id}/change-plan` | change d'offre |
| `/portal/subscriptions/{id}/cancel` | se désabonne (à la fin de la période, ou immédiatement) |
| `/portal/subscriptions/{id}/renew` | renouvelle |
| `/portal/invoices` | voit ses factures, et les télécharge |
| `/portal/invoices/{id}/pay` | paie une facture (carte, MTN MoMo, Orange Money) |
| `/portal/usage` | voit sa consommation et ses quotas |
| `/portal/profile` | voit son profil |

Les offres, c'est **vous** qui les publiez dans le Core (opérateur), une fois :
`POST /v1/plans`, puis une version, puis publication. Une version publiée est
**figée** : les abonnements déjà signés gardent la leur.

## 8. Récapitulatif

| Ce que vous voulez | Appel | Portée |
|---|---|---|
| vérifier la clé | `GET /v1/whoami` | — |
| déclarer un locataire | `PUT /v1/customers/{ref}` | `customers:write` (une fois, à la création) |
| connaître ses droits | `GET /v1/customers/{ref}/entitlements` | `usage:read` |
| compter son usage | `POST /v1/usage` | `usage:write` |
| ouvrir le portail | `POST /v1/portal/sessions` | `portal:write` |
| lire son état commercial | `GET /v1/subscriptions`, `GET /v1/invoices` | `subscriptions:read`, `invoices:read` |
| recevoir ses événements | webhook signé | — |

Et ce que vous **ne** ferez pas : créer une offre, créer un abonnement, émettre une
facture, encaisser. Le portail s'en charge.

---

**Aller plus loin**

- **[Kit Laravel](kit-laravel/INSTALL.md)** — le même parcours, en code prêt à
  l'emploi (à installer en paquet Composer, ADR-0108).
- **[Guide `curl`](guide-curl-integrateur.md)** — paiements, portefeuilles,
  notifications, exports.
- **[Webhooks](webhooks.md)** — la vérification de signature, la rotation du secret,
  la file de rejets.
- **[Manuel de l'intégrateur](../manuel/08-api-integrateur.md)** — *pourquoi* l'API
  se comporte comme elle se comporte.
