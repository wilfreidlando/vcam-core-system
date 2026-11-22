# Brancher une application cliente

> Ce que fait un logiciel qui se branche au Core, et en combien de temps.
> Version de contrat : `/v1`. Décision d'architecture : [ADR-0107](../adr/0107-le-core-est-le-maitre-de-la-relation-commerciale.md).

## 0. Le principe, en une phrase

**Le Core est le maître de la relation commerciale ; votre application est le maître de
ses données métier.** Vous ne gérez ni offre, ni abonnement, ni facture, ni
encaissement : **le propriétaire de votre logiciel fait tout cela lui-même, dans le
portail que le Core héberge.**

Concrètement, votre application fait **six** choses — et, si elle a des clients
qui portent des champs à elle, une **septième** :

| # | Ce qu'elle fait | Comment |
|---|---|---|
| 1 | **Recevoir** son locataire | webhook `customer.created` |
| 2 | **Lire** son identité | `GET /v1/customers/{ref}` |
| 3 | **Lire** ses droits | `GET /v1/customers/{ref}/entitlements` |
| 4 | **Compter** son usage | `POST /v1/usage` |
| 5 | **Ouvrir** le portail de son propriétaire | `POST /v1/portal/sessions` |
| 6 | **Recevoir** son état (abonnement, quotas, factures) | webhooks signés |
| 7 | **Proposer** la forme de ses clients *(facultatif)* | `PUT /v1/projects/{id}/tenant-schema` |
| 8 | **Relire** la forme en vigueur *(facultatif)* | `GET /v1/projects/{id}/tenant-schema` |
| 9 | **Servir** la forme de ses clients *(facultatif)* | `GET /core/tenant-schema` |
| 10 | **Servir** les clients qu'elle a déjà *(facultatif)* | `POST /core/tenants` |

Des **lectures**, un **comptage**, une **ouverture de portail**, la **réception**
d'événements, et — si vous voulez que la console du Core fasse tout le
travail — deux **lectures servies** au Core. **Aucun** droit d'écrire sur le
commerce : votre clé ne portera pas
`customers:write`, ni `subscriptions:write`, ni `invoices:write` — ce n'est pas une
promesse, c'est la portée de la clé.

> **Les lignes 9 et 10 sont facultatives mais recommandées.** C'est grâce à elles
> que le branchement se fait **entièrement dans la console du Core** : pas de
> commande à taper sur votre serveur, pas de fichier à fabriquer, pas d'en-tête à
> deviner ([ADR-0133](../adr/0133-la-console-tire-la-forme-et-les-clients-de-l-application.md)).

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
notifications:write · notifications:read · tenant_schema:write · tenant_schema:read
```

Les deux portées `notifications:*` sont celles des **messages** : votre application
prévient ses propres clients, et le Core n'est que la passerelle WhatsApp. Les
messages ne sont pas du commerce — vous n'écrivez toujours aucun client, aucune
offre, aucun abonnement, aucune facture.

`tenant_schema:write` est la portée de la **déclaration** : elle vous permet de
proposer la forme de vos locataires (section 2 bis). `tenant_schema:read` permet de
**relire** la forme en vigueur. Ce n'est pas une écriture sur le commerce — c'est
une *forme*, une liste de noms, jamais des valeurs.

Vérifiez-la — la réponse dit ce qu'elle est autorisée à faire :

```bash
curl -s "$BASE_URL/v1/whoami" \
  -H "Authorization: Bearer $API_KEY" \
  -H "Accept: application/json"
```

```json
{"project_id":"01a101a1-7a96-712d-b63a-e3bbc27ae721","organization_id":"01a101a1-7a88-7241-b0aa-99f174f09b01","scopes":["customers:read","usage:read","usage:write","portal:write","subscriptions:read","invoices:read","notifications:write","notifications:read","tenant_schema:write","tenant_schema:read"]}
```

Si votre clé porte `*` ou le modèle « Serveur complet », demandez une clé
« Application cliente » : elle est **plus sûre**, et elle ne vous empêche de rien.

## 2. Recevoir son locataire

Le Core est le maître de la relation commerciale **et** de l'identité de votre
locataire. C'est **lui** qui le crée : l'opérateur de la console Core le déclare pour
votre projet — son nom, l'e-mail du responsable, et **la référence que votre
application connaît** — puis le Core **vous le pousse**.

Votre application n'a donc **rien à écrire** : elle **reçoit** son locataire.

L'événement arrive sur le point d'entrée que l'opérateur a enregistré **depuis la
console** du Core, pour votre application — et le secret de signature vous est
**remis hors du Core**, par le canal de votre choix (ADR-0110 décision 5) :

```json
{
  "id": "01a190b8-53be-70d1-9c46-a5b715b4ad80",
  "type": "customer.created",
  "data": {
    "customer_id": "01a190b8-2c4e-7a10-9b6f-8d1e2f3a4b5c",
    "external_ref": "auto-ecole-42",
    "status": "active",
    "version": 1
  }
}
```

`external_ref` est **la référence que votre application connaît** — celle que vous
utilisez dans tous vos appels. C'est l'opérateur du Core qui l'a saisie, pour votre
projet, **pour qu'elle soit la vôtre** : vous n'avez rien à deviner (ADR-0110
décision 3).

La charge utile ne porte **aucune donnée personnelle** (ADR-0070 §3) : ni nom, ni
e-mail, ni téléphone. Si vous devez associer le locataire à un compte local — par son
e-mail, par exemple — **demandez-le** :

```bash
curl -s "$BASE_URL/v1/customers/$TENANT" \
  -H "Authorization: Bearer $API_KEY" \
  -H "Accept: application/json"
```

L'e-mail et le téléphone ne vous sont retournés que si votre clé porte
`customers:read` — la vôtre le porte.

> **Votre application n'écrit jamais un client.** Elle n'a pas `customers:write`, et
> elle n'en a pas besoin : le Core le crée, et le Core vous le dit.
>
> Ce que vous écrivez, c'est **votre** locataire : à la réception de
> `customer.created`, vous le **créez** s'il vous est inconnu, ou vous le **liez**
> s'il existe déjà chez vous. C'est le patron de provisionnement du paquet
> [`core-adapter`](https://github.com/wilfreidlando/core-adapter), dont le README
> fait le tour.

## 2 bis. Déclarer la forme de vos locataires

Le Core sait désormais quelle **forme** portent vos clients : la clé unique que
vous utilisez, le format que vos références doivent respecter, et les champs que
vous attendez de lui — votre localisation, votre responsable, votre année de
création. Vous ne les **inventez** pas au moment de la saisie : vous les
**annoncez**, une fois, et la console du Core les affiche tels quels.

C'est une *forme*, pas une donnée : ni le nom du client, ni son e-mail, ni son
téléphone. Une déclaration ne contient que des **noms** de champs.

> **Le chemin ordinaire n'est plus une commande : c'est la console.**
> Depuis la version 1.7 du paquet `core-adapter`, l'opérateur de la console du
> Core **demande** la forme à votre application, **la valide**, et **vous** la
> lisez et la décidez — sur la page *« Application cliente »* du projet. Le
> `PUT` ci-dessous reste le chemin **programmatique** (une intégration
> automatisée, une CI) ; ce n'est plus le geste du jour.

```bash
curl -s -X PUT "$BASE_URL/v1/projects/$PROJECT_ID/tenant-schema" \
  -H "Authorization: Bearer $API_KEY" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "unique_key": "code",
    "unique_key_pattern": "AE[0-9]{4,8}",
    "fields": [
      {"key": "localisation", "label": "Localisation", "type": "string",
       "required": false, "max_length": 120, "source": "declared.localisation"},
      {"key": "annee", "label": "Année de création", "type": "integer",
       "required": true, "source": "declared.annee"}
    ]
  }'
```

`$PROJECT_ID` est le `project_id` que `GET /v1/whoami` vous a donné.

| Champ | Ce que c'est |
|---|---|
| `unique_key` | le nom de la clé que vous désignez — un mot, pas une valeur |
| `unique_key_pattern` | le format de vos références ; il ne décrit que des références du Core (`^[A-Za-z0-9._:-]{1,128}$`) |
| `fields[].key` | le nom de la clé, côté application |
| `fields[].label` | le libellé que l'opérateur lira |
| `fields[].type` | `string`, `text`, `integer`, `decimal`, `boolean`, `date`, `datetime`, `email`, `phone`, `url` |
| `fields[].required` | `true` : l'opérateur devra le remplir |
| `fields[].max_length` | la longueur maximale |
| `fields[].source` | `declared.<clé>`, ou `core_identity.email` / `.display_name` / `.phone` / `.locale` — une source qui reprend un champ du Core **remplace** ce champ : l'opérateur ne saisit jamais la même valeur deux fois |

> **La source `declared.<clé>` doit nommer la clé du champ.** Sur le champ
> `localisation`, la source est `declared.localisation` ; `declared.emplacement`
> serait **refusée** (`422 invalid-tenant-schema`). Le Core stocke la valeur sous
> la clé du champ — votre application la chercherait ailleurs, et aucune erreur
> ne le dirait ([ADR-0130 §2](../adr/0130-la-forme-du-locataire-l-ordre-des-ecritures-la-source-qui-nomme-la-cle-et-la-relecture-publique.md)).

**La proposition n'est pas une décision.** Elle reste **en attente** jusqu'à ce
que l'opérateur du Core l'ait lue dans la console (*« Forme du client »*) et
l'ait **acceptée**. Tant qu'il ne l'a pas fait, le formulaire du Core ne montre
que l'identité du Core.

**Relire la forme en vigueur** — pour savoir ce que le Core attend de vous,
sans jamais voir une valeur :

```bash
curl -s "$BASE_URL/v1/projects/$PROJECT_ID/tenant-schema" \
  -H "Authorization: Bearer $API_KEY" \
  -H "Accept: application/json"
```

```json
{
  "accepted": {"id": "…", "version": 1, "status": "accepted", "fields": [{"key": "localisation", "label": "Localisation", "type": "string", "required": false, "max_length": 120, "source": "declared.localisation"}]},
  "pending": null
}
```

`accepted` est la forme **en vigueur** ; `pending` est la proposition qui attend
l'opérateur (`null` quand il n'y en a pas). Les deux sont `null` quand la
déclaration n'existe pas encore ([ADR-0130 §4](../adr/0130-la-forme-du-locataire-l-ordre-des-ecritures-la-source-qui-nomme-la-cle-et-la-relecture-publique.md)).

Les valeurs, ensuite, se **lisent** — comme le reste :

```bash
curl -s "$BASE_URL/v1/customers/$TENANT" \
  -H "Authorization: Bearer $API_KEY" \
  -H "Accept: application/json"
```

```json
{
  "external_ref": "AE0001",
  "display_name": "Auto-école Soleil",
  "declared_attributes": {"localisation": "Douala", "annee": "2019"}
}
```

> **Les valeurs déclarées sont conservées en clair**, comme le nom du client
> ([ADR-0123](../adr/0123-le-core-ne-chiffre-plus-les-donnees-client.md)).
> N'y mettez donc pas de donnée personnelle : ni pièce d'identité, ni adresse,
> ni numéro de téléphone.

### Vos clients que le Core ne connaît pas encore

Si votre application avait déjà ses clients **avant** le branchement, ils ne
sont pas chez le Core.

**Le chemin ordinaire : le Core vient les chercher.** Sur la page *« Application
cliente »* du projet, l'opérateur clique sur **« Créer les clients depuis
l'application »** : le Core interroge votre application, qui lui rend la liste
de ses clients **avec les valeurs qu'elle connaît déjà** (la localisation, le
nom du responsable, l'année de création) ; le Core affiche un **aperçu** —
combien seront créés, combien sont déjà connus, combien seront refusés et
pourquoi — puis l'opérateur confirme par **« Créer les clients »**. Le Core les
crée et les pousse.

Votre application, qui les connaît déjà, les **relie** : **aucun doublique**
n'est possible, la référence est unique.

> **Le chemin de secours : le fichier.** Quand l'application est injoignable, ou
> pour un lot hors bande, l'export reste possible (le paquet `core-adapter`
> expose `core:export-tenants`) et l'opérateur dépose le fichier dans la console
> du Core (*« Importer des clients »*). Le fichier doit porter les colonnes de
> la forme en vigueur — la console donne le modèle tout fait. Ce n'est plus le
> parcours ordinaire.

### Les lectures que le Core vient chercher

Pour que le Core fasse tout cela tout seul, votre application doit **servir**
deux lectures. Le paquet `core-adapter` (v1.7 et au-delà) les monte
automatiquement — **vous n'écrivez pas une ligne de code pour les monter**, vous
ne faites que **les remplir**.

**`GET /core/tenant-schema`** — la forme que l'application veut :

```json
{
  "unique_key": "code",
  "unique_key_pattern": "^AE[0-9]{4}([0-9]{2}){0,2}$",
  "fields": [
    {"key": "nom", "label": "Raison sociale", "type": "string",
     "required": true, "source": "core_identity.display_name"},
    {"key": "localisation", "label": "Localisation", "type": "string",
     "required": false, "max_length": 191, "source": "declared.localisation"}
  ]
}
```

**`POST /core/tenants`** — les clients que l'application a déjà, **paginés**.
La page demandée est portée par le **corps** de la requête, pas par la chaîne
de requête :

```json
{"page": 1, "per_page": 100}
```

```json
{
  "data": [
    {
      "external_ref": "AE0042",
      "display_name": "Auto-École La Fontaine",
      "email": "contact@la-fontaine.cm",
      "phone": "+237657467941",
      "localisation": "Douala, Akwa",
      "nom_respo": "Awa Njoya",
      "annee_creation": 2019
    }
  ],
  "meta": {"page": 1, "per_page": 100, "count": 1, "has_more": false}
}
```

Trois règles, et rien d'autre :

| Règle | Pourquoi |
|---|---|
| **Chaque ligne porte `external_ref`** | c'est votre clé unique — la référence que l'opérateur saisira chez le Core ; |
| **Chaque ligne porte l'identité** (`display_name`, `email`, `phone`) | le Core crée le client avec elle ; |
| **Chaque ligne porte les valeurs déclarées**, sous le nom de la clé déclarée | sans elles, le Core ne saurait rien de ce client : il le créerait avec la seule identité, et l'application recevrait un client à moitié renseigné. |

Une valeur déclarée garde son **type** : un champ `integer` doit partir en
nombre (`2019`), pas en date (`"2019-05-04"`) — le Core refuse une valeur d'un
autre type.

**L'authentification de ces lectures est celle du point d'entrée.** Le Core
signe sa requête comme il signe un événement :

```
X-Signature: HMAC-SHA256(timestamp + "." + corps)
X-Timestamp: <unix>
```

tolérance **300 s**. C'est **le même secret** que celui des événements — pas un
secret de plus à créer, pas un secret de plus à faire tourner. Pendant une
rotation, le Core signe avec le secret courant et l'application accepte
**aussi** l'ancien : la rotation ne casse pas le branchement.

**L'adresse** est **déduite du point d'entrée** déjà enregistré pour le projet
(même schéma, même hôte) : l'opérateur ne la saisit pas une seconde fois. La
console la montre et permet de la corriger, au cas où l'API de votre
application ne serait pas sur le même hôte que la réception des événements.

> **Ce que le Core fait de votre réponse : il ne s'y fie pas.** Elle est
> validée par les mêmes règles qu'une proposition reçue par l'API ; une
> application qui ment ne peut pas faire écrire n'importe quoi au Core. Le
> corps des réponses n'est **jamais journalisé** : il porte des noms, des
> e-mails et des téléphones.

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

**L'enregistrement se fait depuis la console du Core**, pas depuis votre
application : l'administrateur du Core y saisit l'URL de votre point d'entrée et la
liste des événements, puis **vous remet le secret hors du Core**, par le canal de
votre choix. Votre application se contente de **recevoir** et de **vérifier la
signature** — la procédure complète, avec le code de vérification dans les trois
langages, est dans **[webhooks.md](webhooks.md)**.

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
| `subscription.supplement_purchased`, `subscription.supplement_activated` | un supplément de quota a été acheté / payé : la limite annoncée par `entitlements` a grandi |
| `invoice.issued`, `invoice.paid`, `invoice.payment_failed`, `invoice.voided` | l'état d'une facture |
| `usage.threshold_reached`, `usage.limit_reached` | un quota est bientôt atteint / atteint |

Chaque charge utile de cette liste porte un `version` : c'est le **verrou optimiste**
de la ressource. Rejouez un événement plus ancien que le dernier que vous avez traité,
et ignorez-le. Un événement livré deux fois ne doit être traité qu'une fois (l'`id` de
l'événement est votre clé de dédoublonnage). Seuls les événements d'usage font
exception : `usage.*` ne porte pas de `version`.

Les événements `customer.*` et `subscription.*` ne portent **aucune donnée
personnelle** : le locataire y est nommé par votre propre référence. Si vous avez
besoin de son e-mail, vous le **demandez** (`GET /v1/customers/{ref}`). La charge
utile exacte des événements `customer.*` est décrite dans le catalogue des
événements de **[webhooks.md](webhooks.md)**.

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
| **recevoir** son locataire | webhook `customer.created` | — |
| **lire** son identité | `GET /v1/customers/{ref}` | `customers:read` |
| connaître ses droits | `GET /v1/customers/{ref}/entitlements` | `usage:read` |
| compter son usage | `POST /v1/usage` | `usage:write` |
| ouvrir le portail | `POST /v1/portal/sessions` | `portal:write` |
| lire son état commercial | `GET /v1/subscriptions`, `GET /v1/invoices` | `subscriptions:read`, `invoices:read` |
| **prévenir** ses clients | `POST /v1/notifications/text` | `notifications:write` |
| suivre l'envoi d'un message | `GET /v1/notifications/{id}` | `notifications:read` |
| **proposer** la forme de ses clients | `PUT /v1/projects/{id}/tenant-schema` | `tenant_schema:write` |
| **relire** la forme en vigueur | `GET /v1/projects/{id}/tenant-schema` | `tenant_schema:read` |
| **servir** la forme de ses clients | `GET /core/tenant-schema` | — (signature du point d'entrée) |
| **servir** ses clients existants | `POST /core/tenants` | — (signature du point d'entrée) |
| recevoir ses événements | webhook signé | — |

Et ce que vous **ne** ferez pas : **créer un client**, créer une offre, créer un
abonnement, émettre une facture, encaisser. Le Core crée le client, et le portail
s'occupe du reste.

---

**Aller plus loin**

- **[Paquet `core-adapter`](https://github.com/wilfreidlando/core-adapter)** — le
  même parcours, en code prêt à l'emploi : `composer require
  wilfreidlando/core-adapter`, service provider auto-découvert
  ([ADR-0108](../adr/0108-le-kit-client-devient-un-paquet-composer.md),
  [ADR-0112](../adr/0112-le-paquet-client-s-appelle-core-adapter.md)). L'ancien
  [kit Laravel](kit-laravel/INSTALL.md) est conservé pour mémoire, mais il est
  remplacé.
- **[Guide `curl`](guide-curl-integrateur.md)** — paiements, portefeuilles,
  notifications, exports.
- **[Webhooks](webhooks.md)** — la vérification de signature, la rotation du secret,
  la file de rejets.
- **[Manuel de l'intégrateur](../manuel/reference/08-api-integrateur.md)** — *pourquoi* l'API
  se comporte comme elle se comporte.
