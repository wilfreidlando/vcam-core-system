# ADR-0122 — Le projet déclare la forme de son locataire ; le Core la collecte, la rend, et n'écrit jamais chez le projet

- **Statut** : Proposé
- **Date** : 2026-11-11
- **Décideurs** : commanditaire (« il faut le cas où l'app metier a deja ces teant et on l'enregistre sur le core pour que desormais le core le reconnaisse »), comité d'architecture
- **Dérives concernées** : D1 périmètre · D3 données/PII · D5 contrat
- **Précise** : ADR-0110 §4 (l'application lit l'identité dont elle a besoin)
- **Amende** : ADR-0107 §5 — le gabarit de portées « Application cliente » gagne la portée `tenant_schema:write`, qui n'est **pas** une écriture sur le commerce

## Contexte

ADR-0110 a tranché la direction : **le Core crée le locataire et le pousse ; l'application cliente ne l'écrit pas**. La règle est saine, et elle est vérifiée mécaniquement : le gabarit de portées « Application cliente » ne porte aucune écriture sur le commerce, et le test de contrat le prouve sur le HTTP.

Mais il manque une chose, et elle se voit dès qu'on branche une vraie application.

**Le Core ne connaît pas le modèle métier d'une application.** Il sait qu'un client porte une référence, un nom, un e-mail, un téléphone. Il ne sait pas que « l'auto-école » a un **code** qui l'identifie, ni qu'elle a une **localisation**, un **nom de responsable** ou une **année de création**. Deux conséquences :

1. **La référence se saisit à l'aveugle.** L'opérateur tape une référence dans le champ du Core, sans savoir le format que l'application emploie. L'application le découvre tard — dans la file du worker, à trois heures du matin — ou jamais.
2. **Les champs que l'application attend ne sont jamais collects.** L'application a besoin de valeurs que le Core n'a pas ; aujourd'hui, elle les prend à défaut, ou elle ne les prend pas.

Il y a une troisième situation, que la première application a révélée : **elle a déjà ses locataires**. Une auto-école existe depuis des années, elle a cent établissements, et le Core n'en connaît aucun. Il faut que le Core les **reconnaisse** — pour les facturer, pour leur pousser leur état, pour que la console affiche le parc.

Ces trois besoins ont la même réponse : **une déclaration**. L'application dit au Core la forme de son locataire ; le Core la collecte et la rend.

Trois contraintes bornent la solution :

- la charge utile de `customer.created` est **persistée en clair** et diffusée à **tous** les points d'entrée abonnés au type : y mettre une valeur de l'application obligerait à amender ADR-0070 §3 et ADR-0083 §3 ;
- le Core ne **jamais** n'appelle l'application pour écrire chez elle (ADR-0084 §1) ;
- l'application cliente n'écrit **aucun** client chez le Core : son gabarit de portées ne porte pas `customers:write`, et le test de contrat le refuse (ADR-0107 §5).

## Décision

**Décision 1 — une déclaration est une forme, jamais des valeurs.**

Une déclaration se compose de :

- la **clé unique** que l'application désigne : l'attribut qui identifie un locataire *chez elle*, et le **motif** que doit respecter la référence du Core pour le devenir ;
- une liste de **clés d'attribut** : un nom, un libellé, un type, l'obligation, la longueur maximale, et la **source** d'où vient la valeur.

Ce sont des **noms**, jamais des valeurs. Le Core ne détient pas le modèle métier : il détient la forme.

**Décision 2 — l'application propose, l'opérateur accepte.**

L'application **propose** sa déclaration par l'API (`PUT /v1/projects/{projectId}/tenant-schema`, portée `tenant_schema:write`). L'opérateur l'**accepte** dans la console, ou la refuse. Seule une déclaration acceptée est rendue et opposable : le Core ne rend jamais une forme que personne n'a lue.

**Décision 3 — le Core ne connaît que des clés, des types et des libellés.**

Il ne sait pas ce qu'est une auto-école, un établissement ou une agence. Une clé s'appelle `localisation` ou `nom_respo` selon ce que l'application l'a appelée ; le Core n'en sait pas plus. C'est ce qui permet à une application qui arrive plus tard de se brancher sans toucher au Core.

**Décision 4 — les valeurs déclarées se lisent, elles ne voyagent pas dans l'événement.**

Le payload de `customer.created` reste `{customer_id, external_ref, status, version}` : **ADR-0070 §3 et ADR-0083 §3 ne sont pas amendés**. Les valeurs déclarées — comme l'identité — se **relisent** par `GET /v1/customers/{ref}`, avec la portée `customers:read` (ADR-0110 §4, précisé par la présente décision).

**Décision 5 — les valeurs déclarées sont conservées en clair, comme `display_name`.**

Elles ne sont ni recherchées, ni indexées, ni chiffrées : ce sont des valeurs d'exploitation, pas des secrets. En revanche :

- elles ne sont **jamais** dans les journaux, ni dans l'audit ;
- elles ne sont rendues qu'à un appelant portant `customers:read` — la même règle que l'e-mail et le téléphone ;
- la page d'acceptation **rappelle** que les clés déclarées ne doivent pas porter de donnée personnelle, et le Core refuse une clé qui duplique une source du Core (`core_identity.email`, par exemple).

**Décision 6 — la clé unique est validée à la saisie, pas dans la file.**

Le motif déclaré est écrit dans l'indice du champ de référence, avant que l'opérateur ne tape. Une référence hors motif est **refusée dans la console**, là où l'erreur est rattrapable. Le motif doit être compatible avec la règle du Core sur les références (`^[A-Za-z0-9._:-]{1,128}$`).

**Décision 7 — le projet crée son locataire ; il n'y a qu'un écrivain.**

À la réception du push, le projet crée son locataire s'il ne le connaît pas, et le **lie** s'il le connaît déjà. Le Core n'écrit jamais chez le projet.

**Décision 8 — la déclaration n'est pas du commerce.**

`tenant_schema:write` est ajoutée à `ApiKeyScopes::ALL` et au gabarit « Application cliente ». Elle **ne touche pas** au commerce : elle décrit une forme, elle n'écrit ni client, ni abonnement, ni facture, ni paiement. La garantie mécanique d'ADR-0107 §5 reste entière — `customers:write`, `subscriptions:write`, `invoices:write` et `plans:write` restent absents du gabarit.

**Décision 9 — les locataires déjà existants passent par un import, fait par l'opérateur.**

Une application qui existait avant le Core a déjà ses locataires. Le Core ne les connaît pas, et **l'application ne peut pas les lui pousser** : lui donner `customers:write` romprait la garantie d'ADR-0107 §5 et inverserait la maîtrise.

Le chemin est donc :

1. l'application **exporte** ses locataires — une lecture seule, un CSV, aucun droit, aucun appel au Core ;
2. l'opérateur **importe** ce CSV dans la console du Core ;
3. le Core **crée** les clients, avec la référence = la clé unique déclarée, et **pousse** `customer.created` ;
4. l'application, qui connaît déjà ces locataires, les **lie** — elle n'en crée aucun.

L'import est donc le chemin de provisionnement lui-même, réutilisé : aucun code de doublon, aucun cas particulier. Un import rejoué ne crée rien de nouveau : la référence est unique au Core.

**Décision 10 — le refus est visible, jamais silencieux.**

Une ligne d'import refusée — référence hors motif, e-mail absent, client déjà connu — est **compte-rendue** dans le rapport d'import, avec sa raison. Un import partiel est un import réussi, et l'opérateur doit le voir.

## Alternatives écartées

**Les valeurs déclarées dans la charge utile de `customer.created`.** — Rejeté : le payload est persisté **en clair** (`event_outbox.payload`, `webhook_deliveries.payload`, conservation 90 jours) et diffusé à **tous** les points d'entrée abonnés au type, y compris le jokers `*`. Y mettre des données oblige à amender ADR-0070 §3 et ADR-0083 §3 — deux règles fondatrices du Core — pour un besoin qu'une lecture authentifiée couvre déjà.

**Le Core appelle l'application pour lire ses locataires.** — Rejeté : ADR-0084 §1 est explicite — « le core n'appelle jamais l'application pour créer ou modifier quelque chose chez elle ». Un canal de pull serait un second plan de transport, donc un second parseur côté application.

**L'application pousse ses clients existants au Core.** — Rejeté : il faut `customers:write`, ce que le gabarit « Application cliente » ne porte pas et ne portera pas (ADR-0107 §5). C'est aussi l'inversion de la maîtrise que le commanditaire a refusée en octobre : « le Core crée le locataire et le pousse ».

**L'opérateur saisit les locataires un par un, à la main.** — Rejeté : pour cent établissements, c'est cent saisies et cent fautes ; et l'identité (raison sociale, e-mail) serait ressaisie de mémoire. L'export puis l'import est le même travail, fait une fois, avec les bonnes données.

**Un `metadata` libre sur le client.** — Rejeté : `metadata` est réservé aux identifiants techniques, plafonné à 16 clés et 4 Ko, et ne doit porter aucune donnée personnelle (ADR-0070 §1). Une déclaration a une **forme** — un nom, un type, une obligation, un libellé — que `metadata` ne sait pas porter.

**Un modèle de locataire par type dans le Core.** — Rejeté : le Core ne connaîtrait plus seulement des clés, il connaîtrait des métiers. C'est la dérive D1, et elle rendrait chaque nouvelle application incompatible du Core.

**Une portée `tenant_schema:write` rangée parmi les écritures commerciales.** — Rejeté : la portée ne décrit pas une écriture sur le commerce, elle décrit une **forme**. La nommer comme une écriture aurait pour effet de ranger une application cliente du niveau des clients du Core — ce qu'elle n'est pas.

## Conséquences

**Ce qui est gagné.**

- L'intégration devient **vérifiable** : l'opérateur sait ce qu'il doit taper, et l'erreur est refusée sous ses yeux.
- L'application n'écrit **toujours** rien chez le Core, et le contrat mécanique reste vert : une application cliente ne peut toujours ni créer un client, ni facturer.
- Les tenants déjà existants trouvent un chemin : l'export et l'import, qui réutilisent le provisionnement livré.
- Une application qui arrive plus tard se branche **sans toucher au Core** : elle déclare ses clés, et c'est tout.

**Ce qui est engagé.**

- Le Core doit porter le **stockage** des déclarations (projet, version, statut, motif, forme) et des **valeurs déclarées** (par client et par clé). Les valeurs déclarées ne sont pas des colonnes de `customers` : c'est une table à part, et la liste blanche de colonnes d'ADR-0070 §1 reste intacte.
- Le Core doit rendre `declared_attributes` dans `GET /v1/customers/{ref}` — sous `customers:read`, comme l'e-mail et le téléphone.
- La console doit offrir trois écrans : le formulaire « Nouveau client » rendu **côté serveur** avec un champ par clé (jamais une zone JSON), la page d'acceptation (une **liste lisible**, jamais un éditeur de JSON), et l'import en lot avec son rapport.
- Le gabarit « Application cliente » change : `ClientApplicationScopeTest` porte une égalité stricte sur la liste des portées — elle doit être mise à jour **explicitement**, dans le même commit.
- Le document OpenAPI doit être régénéré, et la route de console qui porte un identifiant doit être inscrite au manifeste IDOR.

**Ce qui ne change pas.**

- Le Core reste le maître : l'application n'écrit aucun client, et la création d'un client reste un geste d'opérateur dans la console.
- Le transport reste le plan de webhooks : un seul parseur côté application.
- La charge utile d'un événement ne porte aucune donnée personnelle.
- Le commerce du Core reste interdit aux clés d'application cliente.
