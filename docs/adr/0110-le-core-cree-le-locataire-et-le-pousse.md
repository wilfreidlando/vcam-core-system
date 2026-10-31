# ADR-0110 — Le Core crée le locataire et le pousse ; l'application cliente ne l'écrit pas

- **Statut** : Accepté
- **Date** : 2026-10-31
- **Décideurs** : commanditaire (« quand on crée un projet ou client sur le core il crée le tenant dans l'application cliente en question »), comité d'architecture
- **Dérives concernées** : D1 périmètre · D5 contrat
- **Amende** : ADR-0107 §2 (la direction de la déclaration), sans toucher à son §5

## Contexte

ADR-0107 §2 disait : « l'application cliente déclare son locataire une fois, et le
Core le pousse ». Le guide d'intégration, écrit sur cette base, documentait
`PUT /v1/customers/{ref}` — un appel qui exige **`customers:write`**.

Or le modèle de portées « Application cliente » d'ADR-0107 §5 ne porte **pas**
`customers:write` : il ne donne aucune écriture sur le commerce. Les deux
décisions se contredisaient, et le test qui les applique (`ClientApplicationScopeTest`)
refuse `PUT /v1/customers/{ref}` avec cette clé.

La phrase du commanditaire tranche : « quand on crée un projet ou client sur le core
il crée le tenant dans l'application cliente en question ». C'est le **Core** qui crée
le locataire, et c'est lui qui le pousse. L'application ne l'écrit pas.

Trois faits du terrain soutiennent cette lecture :

- l'auto-école — le premier client — **pousse déjà** son locataire : son point
  d'entrée `POST /api/v1/tenants` n'est appelé par personne aujourd'hui, et il est
  protégé par une clé partagée sans signature ni anti-rejeu ;
- le Core **émet déjà** `customer.created` (`UpsertCustomerUseCase.php:87`), et cet
  événement est désormais abonnable (ADR-0107 §3) ;
- la console du Core sait **créer un client** pour un projet
  (`POST /admin/projects/{projectId}/customers`).

## Décision

**Décision 1 — le Core crée le locataire, et le pousse vers l'application.**

L'opérateur de la console Core déclare le client du projet : son nom, l'e-mail du
responsable, et **la référence que l'application connaît**. Le Core crée le client,
puis l'événement `customer.created` part vers le point d'entrée enregistré pour cette
application.

**Décision 2 — l'application cliente n'écrit aucun client.**

Son modèle de portées ne porte pas `customers:write` — ADR-0107 §5 est **inchangé**.
La garantie tient : une application cliente qui tient cette clé ne peut pas créer un
client, quelle que soit la ligne de code qu'elle exécute.

**Décision 3 — la référence du locataire est celle que l'application connaît.**

`external_ref` est saisi par l'opérateur : c'est l'identifiant que l'application
utilise dans tous ses appels (le code de l'auto-école, par exemple). Elle n'a pas à le
deviner, et elle le reçoit dans la poussée.

**Décision 4 — l'application lit l'identité dont elle a besoin.**

La charge utile d'un événement ne porte aucune donnée personnelle (ADR-0070 §3). Si
l'application doit associer le locataire à un compte local — par son e-mail, par
exemple — elle la **demande** par `GET /v1/customers/{ref}`, avec `customers:read`.

**Décision 5 — le point d'entrée de l'application s'enregistre depuis la console.**

C'est ADR-0107 §6, et c'est la brique qui rend la poussée possible : les points
d'entrée webhook sont aujourd'hui gérés **par API seulement** (`POST
/v1/webhook-endpoints`), et l'opérateur doit pouvoir le faire depuis la console, comme
il le fait pour une session WhatsApp.

Le secret qui en découle change de main : c'est l'opérateur qui le remet à
l'application, hors du Core. C'est la contrepartie du fait que l'application n'a
plus de clé d'écriture : elle n'a pas à détenir de secret de webhook — l'opérateur
le lui remet en main propre.

## Alternatives écartées

**L'application déclare elle-même son locataire (`PUT /v1/customers/{ref}`).** —
Rejeté : exige `customers:write`, contredit ADR-0107 §5, et surtout inverse la
maîtrise — le Core ne serait plus le maître de l'identité du locataire, alors que
c'est lui qui porte le commerce, le quota et le portail. La phrase du commanditaire
est explicite sur ce point.

**Un canal de provisionnement dédié (URL + secret par projet), distinct des
webhooks.** — Rejeté : le plan de webhooks couvre déjà le besoin — même enveloppe
`{id, type, data}`, même signature HMAC, même dédoublonnage par `id`, mêmes reprises
et même file de rejets. Un second plan de livraison, ce serait un second parseur
côté application, pour rien. La brique qui manque est l'enregistrement **depuis la
console** (décision 5), pas le transport.

**Le Core qui pousse l'identité complète (e-mail, téléphone) dans l'événement.** —
Rejeté : ADR-0070 §3 est une règle du Core, pas une préférence. La donnée est
lisible par un appel authentifié et tracé, plutôt que diffusée à tous les abonnés
d'un événement.

## Conséquences

**Ce qui est gagné.** — L'application cliente n'écrit **rien** sur le commerce : cinq
surfaces, toutes en lecture, en comptage ou en réception. Et le parcours d'intégration
a un début **unique et vérifiable** : le push.

**Ce qui est engagé.**

- Le Core doit offrir une **page de console** pour enregistrer le point d'entrée
  d'une application cliente : URL, secret (montré une fois), liste d'événements. C'est
  du travail de console, pas d'API.
- Le **secret de webhook** se remet **hors du Core** : l'opérateur le donne à
  l'application. C'est un changement de pratique, à dire et à documenter.
- L'application doit savoir **faire** de la réception d'événements (dédoublonnage,
  rejet des événements plus vieux que le dernier traité) — c'est le rôle du paquet.
- La documentation du kit et du guide d'intégration doivent dire **« reçoit »**, jamais
  « déclare ».

**Ce qui ne change pas.** — Le commerce reste dans le portail, le quota reste dans le
Core, et la fiche du locataire reste consultable dans la console.
