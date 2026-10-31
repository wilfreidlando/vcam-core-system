# ADR-0107 — Le Core est le maître de la relation commerciale ; l'application cliente ne fait pas de commerce

- **Statut** : Accepté
- **Date** : 2026-10-31
- **Décideurs** : commanditaire (« je ne vois pas pourquoi il est dans le kit alors que le portail le permet de gérer tout ça » ; « le but c'est que l'application client ne fasse vraiment pas trop d'intégration d'api » ; « quand on crée un projet ou client sur le core il crée le tenant dans l'application cliente en question »), comité d'architecture
- **Dérives concernées** : D1 périmètre · D5 contrat
- **Précise** : ADR-0075 (portail), ADR-0084 (le locataire du SaaS est un client du Core), ADR-0070 §3 (aucune donnée personnelle dans les charges utiles)
- **Amendé par** : ADR-0110 (§2 — le Core crée le locataire et le pousse ; l'application ne l'écrit pas)

## Contexte

Le portail du Core possède déjà **toute** l'interface commerciale d'un client : `GET
/portal/plans`, `POST /portal/plans/{code}/subscribe`, `POST /portal/subscriptions/{id}
/cancel`, `…/change-plan`, `…/keep`, `…/renew`, `GET /portal/invoices`,
`POST /portal/invoices/{id}/pay`, `GET /portal/usage`, `GET /portal/profile`
(`packages/portal/routes/web.php:41-58`). Le propriétaire y choisit son offre, s'abonne,
change d'offre, renouvelle, paie ses factures.

Le kit client, lui, exposait la même chose **par l'API** : `provision()` (création du
client), `plan_code`, plus les appels de factures. L'application cliente faisait donc une
intégration d'API considérable pour une chose que le portail fait déjà — et les trois
bogues relevés dans le kit naissent tous de là :

| Écart | Fait |
| --- | --- |
| G3 | `GET /v1/customers/{ref}/entitlements` exige `usage:read` ; le gabarit « Portail uniquement » (`portal:write` + `customers:read`) renvoie **403**. Or c'est la porte que le kit utilise pour savoir si un locataire a des droits. |
| G4 | `POST /v1/portal/sessions` avec `customer` exige `customers:write` et avec `plan_code` exige `subscriptions:write` : le même gabarit renvoie **403**. Le kit devait donc **créer le client et l'abonnement** — c'est-à-dire faire le commerce à la place du portail. |
| G9 | `config/core.php` annonce des noms de gabarits qui n'existent pas. |

La reconnaissance de l'application auto-école — le premier client — montre que le
modèle est déjà le bon, et que le commerce n'y a **rien** à faire : le tenant y est
**poussé** par la plateforme (`POST /api/v1/tenants`), il n'existe **aucun** signup
public, et il n'existe **aucun** écran plan, abonnement, facture ou paiement. Le kit
n'y a pas sa place.

Il reste une tension à lever : le portail ne peut s'abonner que pour un client
**existant**, et aucun point d'entrée du portail ne crée de client
(`OpenPortalSessionUseCase.php:99-117` : c'est l'appel `POST /v1/portal/sessions` qui
crée le client, et il est protégé par une clé d'API). Sans client, pas de portail.

## Décision

**Décision 1 — le Core est le maître de la relation commerciale.**

Le Core crée le client, l'abonnement, les factures ; il porte le quota ; il héberge le
portail. L'application cliente **ne gère aucun objet commercial**. Elle :

| Capacité | Appel | Portée |
| --- | --- | --- |
| connaître son locataire | `GET /v1/customers/{ref}` | `customers:read` |
| lire ses droits | `GET /v1/customers/{ref}/entitlements` | `usage:read` |
| compter son usage | `POST /v1/usage` | `usage:write` |
| ouvrir l'espace de gestion | `POST /v1/portal/sessions` | `portal:write` |
| recevoir son état | webhook signé | — |

Cinq surfaces. Le commerce lui-même — offres, abonnements, factures, encaissements —
n'en fait pas partie : il se passe dans le portail, où le propriétaire le fait lui-même.

**Décision 2 — l'application cliente déclare son locataire une fois, et le Core le pousse.**

L'application cliente possède ses données métier (une auto-école a une adresse, une
année de création, un responsable) que le Core ne peut pas inventer. Elle **enregistre**
donc son locataire une fois, auprès du Core, et n'y touche plus.

En contrepartie, le Core **pousse** l'état commercial vers l'application cliente : le
plan, l'état d'abonnement, les quotas, les factures. La poussée passe par **le plan de
webhooks existant** — même enveloppe `{id, type, data}`, même signature HMAC-SHA256,
même dédoublonnage par `id` (ADR-0061). Un seul parseur côté client, comme aujourd'hui.

**Décision 3 — les événements qui portent l'état du locataire deviennent abonnables.**

Le Core **émet déjà** `customer.created` (`UpsertCustomerUseCase.php:87`),
`customer.updated` (`:132`), `customer.erased` (`EraseCustomerUseCase.php:86`) — mais
ils ne figurent pas dans `WebhookEventTypes::ALL` (`:42-43`), qui ne contient que
`customer.suspended` et `customer.reactivated`. Un projet ne peut donc pas s'y abonner
par nom (`RegisterWebhookEndpointRequest.php:63` : `Rule::in(['*', ...ALL])`) : il les
recevrait seulement par le joker, s'il s'abonnait à tout.

C'est un **écart**, pas une intention : la spécification technique annonce
`customer.created, customer.updated, customer.suspended, customer.reactivated,
customer.erased` (`docs/specs/spec-technique.md:958`) et ADR-0070 §5 les liste aussi.
Le catalogue est donc **complété** pour correspondre à ce que le Core émet déjà. Même
traitement pour `invoice.created`, `invoice.receipt_recorded` et `invoice.uncollectible`,
émis et non abonnables.

**Décision 4 — la charge utile reste sans donnée personnelle.**

ADR-0070 §3 et ADR-0083 font une règle du Core : un événement ne porte **ni nom, ni
e-mail, ni téléphone**. Le client est nommé par `external_ref`, la référence opaque que
l'application connaît. Cette règle **tient** : la poussée ne transporte pas l'identité
personnelle.

Conséquence : si l'application a besoin de l'e-mail du locataire (pour créer un compte
local), elle le **demande** par `GET /v1/customers/{ref}` — un appel de lecture, avec
`customers:read`. La Core est le système de référence pour l'identité ; l'application
la lit, elle ne la reçoit pas en pushed.

**Décision 5 — un gabarit de portées dédié à l'application cliente.**

Le gabarit « Portail uniquement » (`portal:write` + `customers:read`) ne peut pas lire
les droits ni compter l'usage : **403**. Il est donc **complété** en un gabarit
« Application cliente », qui couvre exactement ce que la décision 1 énumère :

```
customers:read · usage:read · usage:write · portal:write
```

`subscriptions:read` et `invoices:read` s'y ajoutent pour que l'application puisse
**lire** son état commercial — sans jamais l'écrire. Les écritures (`customers:write`,
`subscriptions:write`, `invoices:write`, `plans:write`) **n'y sont pas** : c'est la
garantie mécanique que l'application cliente ne peut pas faire de commerce, quel que
soit le code qu'elle exécute.

La décision 1 devient ainsi **vérifiable** : une application cliente qui n'a que ce
gabarit ne *peut pas* créer un client, un abonnement ni une facture. Le code n'a pas
besoin d'être correct, il n'a pas le pouvoir.

**Décision 6 — le point d'entrée webhook de l'application s'enregistre depuis le Core.**

Le commanditaire l'a posé : « les webhooks seront enregistrés à partir du Core ».
L'enregistrement d'un point d'entrée est donc une action **opérateur**, faite dans la
console du Core, et non un appel de l'application cliente. Cela retire du kit
`POST /v1/webhook-endpoints` et sa rotation, et cela retire au client la possession
d'un secret qu'il n'a pas à gérer.

**Décision 7 — ce qui reste à corriger, et qui n'est pas de l'architecture.**

- Le gabarit « Portail uniquement » reste tel quel (il sert à ouvrir un portail sans
  compter l'usage) ; c'est le **nouveau** gabarit « Application cliente » qui sert aux
  applications.
- `subscription.*` ne porte pas `plan_code` (`SubscriptionEvents.php:23-42`) : une
  application qui veut connaître son offre la **demande** (`GET /v1/subscriptions/{id}`).
- Les charges utiles `usage.*` ne portent pas de `version` : l'ordre de deux événements
  d'usage ne peut pas être garanti. Le `version` d'une ressource est un verrou
  optimiste ; un compteur d'usage en est dépourvu par nature. Accepté, et documenté.

## Alternatives écartées

**Garder le commerce dans le kit.** — Rejeté : c'est la duplication du portail, et la
source des trois bogues G3/G4/G9. Le commanditaire l'a formulé : « je ne vois pas pour
quoi il est dans le kit alors que le portail le permet de gérer tout ça ».

**Laisser l'application cliente créer le client et l'abonnement (l'inverse).** — Rejeté :
le client app devrait alors porter `customers:write` et `subscriptions:write`, c'est-à-dire
le pouvoir de faire du commerce — et c'est précisément ce qu'on lui retire. Le Core
n'est plus le maître.

**Un canal de provisionnement dédié (URL + secret par projet), distinct des webhooks.**
— Rejeté pour l'instant : ce serait un second plan de livraison, donc un second
parseur, une seconde file, une seconde DLQ côté client, pour un besoin que le plan de
webhooks couvre déjà — à la condition que les événements manquants soient abonnables
(décision 3) et que l'enregistrement vienne de la console (décision 6). La brique
manquante est le catalogue, pas le transport. Si un jour une poussée doit porter autre
chose que des événements (une commande synchrone, une réponse), on lui ouvre son propre
canal — les primitives (`HttpTransport`, `RequestSigner`, `SafeOutboundUrlRule`) sont
déjà là.

**Mettre l'identité personnelle dans la charge utile de la poussée.** — Rejeté : ADR-0070
§3 est une règle du Core, pas une preference ; et la donnée est lisible par un appel
(authentifié, tracé) plutôt que diffusée à tous les abonnés d'un événement.

## Conséquences

**Ce qui est gagné.** — L'application cliente fait **cinq** choses, pas une gestion
commerciale. Le propriétaire gère son offre, son abonnement et ses factures **lui-même**,
dans le portail, sur son téléphone. Le kit n'a plus trois bogues, mais zéro.

**Ce qui est engagé.**

- Le Core doit **compléter le catalogue d'événements** (décision 3) — aligner le code
  sur ce que la spécification annonce déjà.
- Le Core doit **créer le gabarit « Application cliente »** (décision 5) et le
  documenter dans le manuel des clés.
- Le Core doit **offrir l'enregistrement d'un point d'entrée webhook depuis la
  console** (décision 6) : c'est du travail de console, pas d'API.
- L'application cliente doit pouvoir **recevoir** une poussée signée : son point
  d'entrée actuel (`POST /api/v1/tenants`, protégé par une clé partagée `X-API-Key`,
  sans signature ni anti-rejeu) doit vérifier la signature HMAC et dédoublonner par
  `id`. C'est le travail du paquet.
- Le paquet doit exposer l'**enregistrement du locataire** (une fois) — et c'est la
  seule écriture que l'application fait sur le Core.

**Ce qui n'est pas décidé ici.** — La forme exacte de l'écran de la console qui
enregistre le point d'entrée d'une application cliente, et le nom du gabarit de
portées. Ils sont objets de la décision d'outillage qui suit (ADR-0108).
