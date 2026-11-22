# ADR-0130 — La forme du locataire : l'ordre des écritures, la source qui nomme la clé, et la relecture publique

- **Statut** : Proposé
- **Date** : 2026-11-19
- **Décideurs** : commanditaire (« que le core reflectance la valeur declaree avant l'evenement, et que la source nomme la cle »), comité d'architecture
- **Dérives concernées** : D5 contrat · D3 données/PII
- **Précise** : ADR-0122 §2, §5, §6 (la déclaration de forme), ADR-0070 §3 (la charge utile de `customer.created`)

## Contexte

ADR-0122 a décidé que le projet **déclare la forme de son locataire** et que le
Core la collecte. Le code de la déclaration est livré ; l'application cliente
s'y branche. Mais la première application réelle a fait apparaître **cinq
failles**, toutes de la même famille : le Core sait ce qu'il doit faire, et ne
le fait pas encore au bon endroit, ou pas tout à fait.

**La première — l'ordre.** L'opérateur enregistre un client, avec sa
localisation et son année de création. Le Core crée le client, écrit
`customer.created` dans l'outbox, **puis** enregistre les valeurs déclarées.
L'application reçoit l'événement, relit le client — et ne trouve **rien** : son
locataire naît sans localisation, sans responsable, sans année. L'échec est
**silencieux** : rien ne signale l'erreur, et le résultat est un locataire
incomplet chez l'application.

Le code disait la bonne chose dans le mauvais ordre, et l'ordre n'était écrit
nulle part : le cas d'usage d'upsert ignorait les valeurs déclarées, et la
console les écrivait dans un second temps, après l'événement.

**La deuxième — la source qui ne nomme pas la clé.** Une source
`declared.<clé>` désigne la valeur que l'opérateur saisit **pour cette clé**.
Rien n'obligeait le suffixe à nommer la clé du champ. Une application pouvait
déclarer le champ `localisation` avec la source `declared.emplacement` : le
Core stockait la valeur sous `localisation` — comme il se doit — et
l'application la cherchait sous `emplacement`. La valeur était **absente**, et
**aucune erreur** ne le disait, ni au moment de la déclaration, ni après.

**La troisième — la forme ne se relisait pas.** L'application pouvait
**proposer** sa forme, mais elle ne pouvait pas **relire** celle qui est en
vigueur. Elle devait donc se souvenir de ce qu'elle avait proposé, et deviner
ce que l'opérateur avait accepté — alors que le Core, lui, le sait.

**La quatrième — les propositions s'empilaient.** Une application qui se
relance (`post-install`, `deploy`) proposait la **même** forme : le Core
créait une version de plus, et l'opérateur lisait une file de formes mortes.
Le versionnement, qui doit dire ce qui change, disait « rien ».

**La cinquième — la clé unique n'était lue par personne.** `unique_key` est
dans la déclaration depuis ADR-0122 §1 : le nom de l'attribut que
l'application désigne. Le Core ne le lisait nulle part — et rien ne le disait,
ni dans la documentation, ni dans le contrat public.

Deux autres points ont été vérifiés et ne demandaient **rien** : le refus d'une
valeur hors forme, et le refus d'une valeur manquante. En revanche, les dix
types que le Core connaît sont bien rendus par **un seul** contrôle HTML dans
la console — la fonction qui les mappe existe, elle était simplement
inutilisée.

## Décision

**Décision 1 — les valeurs déclarées sont écrites **avant** l'événement, dans la
même transaction.**

L'ordre est la règle. L'événement part en file ; l'application relit le client
et doit y trouver ce que l'opérateur vient de saisir.

Concrètement : la commande d'upsert porte désormais les **valeurs déclarées** ;
le cas d'usage les écrit **dans la transaction du client**, avant l'appel à
l'outbox. La console ne les écrit plus **après** : elle passe par le même
chemin, et il n'y a donc plus de fenêtre entre le client et ses valeurs.

Ce n'est pas un détail d'implémentation, c'est la garantie que l'application
reçoit un client **complet**. Un événement ne part jamais d'un état
partiel — c'est la règle de l'outbox transactionnel (ADR-0032) appliquée aux
valeurs déclarées.

**Les valeurs ne voyagent pas dans la charge utile.** La charge utile de
`customer.created` reste `{customer_id, external_ref, status, version}` :
ADR-0070 §3 et ADR-0083 §3 ne sont pas amendés. L'application **relit** les
valeurs par `GET /v1/customers/{ref}`.

**Décision 2 — une source `declared.<clé>` nomme la clé du champ.**

Le suffixe de la source est le nom de la clé sous laquelle la valeur est
stockée et rendue. `declared.localisation` sur le champ `localisation` :
conforme. `declared.emplacement` sur le champ `localisation` : **refusé** —
`422 invalid-tenant-schema`, avec la raison dans le corps de la réponse.

La règle est dans le **domaine**, pas dans la requête : elle vaut pour toutes
les voies d'écriture, et elle est écrite dans le contrat public (la demande
HTTP et le document OpenAPI).

**Décision 3 — les dix types du Core ont un contrôle, choisi par une seule
fonction.**

La console mappe les dix types que le Core connaît (`string`, `text`,
`integer`, `decimal`, `boolean`, `date`, `datetime`, `email`, `phone`, `url`)
vers un contrôle HTML — et cette décision est **une seule**, dans une seule
fonction :

| Type du Core | Contrôle |
|---|---|
| `string` | `text` |
| `text` | `textarea` |
| `integer` | `number` |
| `decimal` | `number` |
| `boolean` | `checkbox` |
| `date` | `date` |
| `datetime` | `datetime-local` |
| `email` | `email` |
| `phone` | `tel` |
| `url` | `url` |

Une case à cocher **dit toujours** sa valeur : un champ caché porte le « 0 »,
la case porte le « 1 » — une case décochée est un « non », jamais un silence.

Un test le prouve, champ par champ.

**Décision 4 — la forme en vigueur se relit publiquement.**

`GET /v1/projects/{projectId}/tenant-schema` rend la déclaration **acceptée**
(`accepted`) et la proposition **en attente** (`pending`). La portée
`tenant_schema:read` est créée, à côté de `tenant_schema:write` ; elle rejoint
`ApiKeyScopes::ALL` et le gabarit « Application cliente ».

C'est une **forme** : des noms, des types, des obligations. Aucune valeur n'y
passe. La garantie d'ADR-0107 §5 reste entière — la relecture n'écrit rien sur
le commerce.

Le chemin ne nomme jamais un autre projet : une forme qui n'est pas la sienne
est un `404`, comme partout ailleurs (ADR-0069 §2).

**Décision 5 — une proposition identique n'est pas une version ; la clé unique
n'est lue par personne.**

Une proposition **identique** à une forme que le Core connaît déjà — la même
clé unique, le même motif, les mêmes champs, aux mêmes attributs près — **n'écrit
rien** : la réponse est `200` et porte la déclaration existante. Les versions ne
s'empilent pas sur la même forme.

Et `unique_key` est **explicitement** conservé sans usage : le Core ne lit pas
le modèle de l'application ; ce qui fait foi au Core, c'est le **motif**. La
clé est conservée telle quelle dans la déclaration, parce qu'elle fait partie
du contrat que l'application a avec elle-même — et le contrat public le dit.

## Alternatives écartées

**Les valeurs déclarées dans la charge utile de `customer.created`.** — Rejeté
(déjà écarté par ADR-0122 §4) : la charge utile est persistée **en clair**
(`event_outbox.payload`, conservation 90 jours) et diffusée à tous les points
d'entrée abonnés au type. Une lecture authentifiée couvre déjà le besoin.

**L'application écrit elle-même les valeurs, chez elle, à la réception de
l'événement.** — Rejeté : ce serait laisser l'application décider de ce qui
existe, et le Core ne serait plus le maître (ADR-0110 §1). Elle **relit** ce
que le Core a écrit — c'est tout.

**La console continue d'écrire les valeurs dans un second temps, après
l'upsert.** — Rejeté : c'est la faille elle-même. Deux transactions, une
fenêtre, et l'application voit un client incomplet.

**Un `422` sur une proposition identique.** — Rejeté : une proposition
identique n'est pas une erreur, c'est la **même** demande. La réponse doit se
comporter comme un `GET` : `200` et la forme. Un `422` obligerait l'application
à distinguer « refus » de « déjà connu ».

**Un `GET` de la forme rendu sous `customers:read`.** — Rejeté : la forme n'est
pas le commerce, et la portée `customers:read` est celle qui **sort** l'identité
du client. Une portée dédiée dit ce qu'elle protège : ici, une **forme**.

**`unique_key` retirée de la déclaration.** — Rejeté : l'application a
déclaré cette clé, elle la relit, elle la montre. La retirer romprait le
contrat que l'application a avec elle-même pour une raison qui n'appartient qu'au
Core. Elle reste, et le contrat public dit qu'elle ne sert à rien ici.

## Conséquences

**Ce qui est gagné.**

- L'application reçoit un client **complet** : elle relit le client depuis
  l'événement et trouve les valeurs. L'échec silencieux a disparu.
- Une déclaration ne peut plus désigner une valeur **inatteignable** : la
  source nomme la clé, ou elle est refusée à la déclaration.
- L'application sait **ce qui est en vigueur** : elle relit sa forme, au lieu
  de se souvenir de ce qu'elle avait proposé.
- Les versions d'une forme ne s'empilent plus : le versionnement recommence à
  dire ce qui change.
- Le contrat public est complet : la portée `tenant_schema:read` est
  documentée, la règle de la source est écrite, la liste des portées est à jour.

**Ce qui est engagé.**

- Le cas d'usage d'upsert dépend désormais de deux ports de plus (la forme en
  vigueur et les valeurs déclarées) ; il refuse une écriture de valeurs
  déclarées **sans** forme acceptée — c'est la règle d'ADR-0122 §2, qui
  s'applique désormais aussi à cette voie.
- La console n'écrit plus les valeurs par le port de remplacement : elle passe
  par l'upsert. Le doublon de test qui suivait l'écriture de la console a été
  retiré — les valeurs sont lues **en base**, là où elles sont.
- Le document OpenAPI est **régénéré** : une route est ajoutée, une règle de
  champ est décrite, une portée est créée.
- Le contrat de portées de l'application cliente change : `tenant_schema:read`
  rejoint la liste — et la liste est vérifiée par un test d'égalité stricte.

**Ce qui ne change pas.**

- Le Core reste le maître : l'application n'écrit aucun client (ADR-0107 §5,
  ADR-0110 §1).
- La charge utile d'un événement ne porte aucune donnée personnelle.
- La règle des dix types et le refus des valeurs hors forme sont inchangés.
- Le commerce du Core reste interdit aux clés d'application cliente.
