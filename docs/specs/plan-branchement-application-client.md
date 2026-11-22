---
statut: Plan — à faire voter (comité d'architecture)
date: 2026-11-22
commanditaire: « je veux des actions dans la console core […] tout via le core […] prend tout les cas le but est que ce soit vraiment simple et le moins technique possible de tous les côtés »
decide: ADR-0133
---

# Plan — Brancher une application cliente, entièrement depuis la console du Core

## 1. Le problème, en une phrase

Aujourd'hui, brancher une application cliente demande **trois gestes techniques
hors de la console** : une commande sur le serveur de l'application
(`core:declare-tenant-schema`), un fichier CSV à fabriquer et à déposer, et un
secret à copier dans deux `.env`. Le Core, lui, sait faire le reste — mais il
ne sait pas **interroger** une application : c'est le seul geste qui lui
manque, et il est la cause de tous les autres.

## 2. La cible

> Un projet branché au Core en **quatre clics et deux recopies de secrets**,
> sans commande, sans fichier, sans connaître le protocole.

| # | Geste | Qui le fait | Où |
|---|-------|--------------|-----|
| 1 | Enregistrer le point d'entrée des événements | l'opérateur | console (existe) |
| 2 | Recopier le secret dans l'application | l'opérateur | console → `.env` de l'app (une recopie) |
| 3 | Créer la clé d'API « Application cliente » | l'opérateur | console (existe) |
| 4 | Recopier la clé dans l'application | l'opérateur | console → `.env` de l'app (une recopie) |
| 5 | **« Brancher l'application »** | l'opérateur | console (nouveau) |
| 6 | **« Accepter la forme »** | l'opérateur | console (nouveau) |
| 7 | **« Créer les clients de l'application »** | l'opérateur | console (nouveau) |
| 8 | **« Confirmer »** | l'opérateur | console (nouveau) |

Après l'étape 8, tout est automatique : un client créé dans la console du Core
part vers l'application, qui le provisionne ; un client créé dans l'application
n'exige rien (le Core reste le seul écrivain du commerce — ADR-0107 §5).

## 3. La décision qui débloque tout

**Le Core peut interroger une application.**

C'est la seule chose nouvelle. Elle tient en trois règles :

1. **L'adresse de l'application** est celle du point d'entrée déjà enregistré
   (même schéma, même hôte). L'opérateur ne la saisit pas une seconde fois ;
   la console la montre, et la laisse corriger au cas où l'API de l'application
   n'est pas sur le même hôte.
2. **L'authentification** est le secret du point d'entrée, déjà connu des deux
   parties, avec **le même schéma de signature** que les événements que le
   Core envoie déjà : `X-Signature = HMAC-SHA256(timestamp + "." + corps)`,
   `X-Timestamp`, tolérance 300 s. Le paquet `core-adapter` vérifie déjà ce
   schéma (`CoreAdapter\Webhooks\Signature`) : la vérification du/Core→app est
   la même fonction, retournée.
3. **Deux routes** sont servies par le paquet, sur l'application :
   - `GET /core/tenant-schema` — la forme que l'application veut ;
   - `POST /core/tenants` — les locataires qu'elle a déjà, **paginés**. La
     page est portée par le **corps** : c'est le corps qui est signé. Un
     `GET` paginé porterait sa pagination hors de la signature.

Le Core ne fait que **lire** chez l'application. Il n'écrit rien de neuf :
une déclaration récupérée devient une **proposition** (comme celle que
l'application proposait), et une liste de locataires devient un **rapport de
rapatriement** (comme celui de l'import). Les chemins d'écriture, d'audit et
d'événements sont ceux d'aujourd'hui.

## 4. L'écran : une page, trois états

Page unique : **Projet → Clients → *Application cliente*** (nouvelle route
`projects.customers.application`).

Elle montre ce qui est fait, ce qui manque, et le bouton correspondant. Pas
de jargon, pas de JSON, pas de « champ » technique.

```
┌─ Application cliente ─────────────────────────────────────────────┐
│                                                                   │
│  ① L'application reçoit-elle les événements ?                     │
│    ● https://api.gestion-ae.visibilitycam.com/webhooks/core       │
│      Événements : customer.created, customer.updated, …            │
│      [ Modifier ]                                                  │
│                                                                   │
│  ② L'application parle-t-elle au Core ?                            │
│    ● Clé « Application cliente » — pk_live_…3f2a                   │
│      [ Créer la clé ] [ Faire tourner ]                            │
│                                                                   │
│  ③ La forme du client                                              │
│    ○ Aucune forme déclarée                                        │
│      [ Récupérer la forme de l'application ]                       │
│                                                                   │
│  ④ Les clients de l'application                                    │
│    ○ Aucun client connu du Core                                   │
│      [ Créer les clients depuis l'application ]                    │
│                                                                   │
└───────────────────────────────────────────────────────────────────┘
```

Chaque bloc a **trois états** : fait, à faire, ou impossible (avec la
raison). « Impossible » n'est jamais un échec muet : le bouton est désactivé
et la raison est écrite en français, juste dessous.

### 4.1 L'action « Récupérer la forme de l'application »

Un clic. Le Core interroge `GET {base}/core/tenant-schema`, **valide** la
déclaration avec les mêmes règles que `PUT /v1/projects/{id}/tenant-schema`
(motif, types, sources — ADR-0122 §6, ADR-0130 §2), et l'enregistre comme
**proposition en attente**. La page affiche alors la proposition **en liste** :
clé unique, motif, et un tableau des champs (nom, type, obligatoire, origine,
longueur). L'opérateur clique **« Accepter la déclaration »** — ou **« Refuser »**.

L'acceptation reste un clic délibéré : la forme décide **ce que l'opérateur
devra saisir pour chaque client**. C'est une décision, pas une formalité.

> La proposition **identique** à une forme déjà connue ne crée pas de version
> de plus (ADR-0130 §3) : la console le dit et s'arrête.

### 4.2 L'action « Créer les clients depuis l'application »

Un clic. Le Core interroge `POST {base}/core/tenants` et affiche un **aperçu** :

```
  12 clients de l'application
  ├  9 seront créés          (nouveau chez le Core)
  ├  2 sont déjà connus      (mis à jour, jamais dupliqués)
  └  1 sera refusé           · AE0031 — l'adresse électronique est déjà
                                                 portée par un autre client
```

Un clic **« Créer les 11 clients »**. Les refus ne sont pas créés : ils sont
listés avec leur motif, comme le rapport d'import.

> L'aperçu compte ce qu'il peut savoir avant l'écriture. Un refus qui
> n'apparaît qu'**au moment** d'écrire — une adresse électronique déjà portée
> par un client que la même passe vient de créer, une ligne qu'un tiers a
> écrite entre-temps — ne se voit que dans le **rapport** final, avec son
> motif. L'aperçu ne prétend pas tout savoir : il annonce un ordre de
> grandeur, le rapport dit ce qui a réellement eu lieu.

Le chemin d'écriture est **exactement** celui de l'import en lot
(`UpsertCustomer`, audit, `customer.created`) : le fichier et la liste tirée
de l'application convergent vers le même cas d'usage. Une seule logique, une
seule garantie.

## 5. Tous les cas

Le tableau est la vraie spécification : chaque cas a **ce que l'opérateur
voit** et **ce que le Core fait**.

### 5.1 Avant que l'application soit prête

| Cas | Ce que l'opérateur voit | Ce que le Core fait |
|---|---|---|
| Aucun point d'entrée | « Enregistrez d'abord le point d'entrée des événements. » (bouton désactivé) | rien |
| Point d'entrée sans secret connu de l'app | l'avertissement « le secret doit être recopié dans l'application » | rien |
| Aucune clé d'API | « Créez la clé de l'application. » (bouton désactivé) | rien |
| L'application ne répond pas | « L'application n'a pas répondu. Elle est joignable ? » | rien — **aucune** proposition, **aucun** client |
| L'application répond en 404 | « Cette application ne parle pas encore au Core. Elle doit porter la version 1.7 du paquet. » | rien |
| La signature est refusée | « Le secret ne correspond pas : le Core et l'application n'ont pas le même secret. » | rien — et **rien n'est écrit** |
| L'application est en panne au milieu de la pagination | « L'application a répondu pour 40 clients sur 120. Relancez pour continuer. » | les 40 déjà créés restent ; le rejeu est **idempotent** (la référence est unique) |

### 5.2 La forme

| Cas | Ce que l'opérateur voit | Ce que le Core fait |
|---|---|---|
| Aucune forme acceptée | « Aucune forme déclarée. » | — |
| Forme acceptée, identique à celle de l'app | « La forme en vigueur est déjà celle de l'application. » | rien (pas de version de plus) |
| Forme acceptée, différente | la proposition en attente + la forme en vigueur, côte à côte | la proposition est **en attente** ; l'opérateur décide |
| La déclaration est invalide (motif interdit, source qui ne nomme pas la clé) | le motif du refus, en français | rien — **la proposition n'est pas enregistrée** |
| La déclaration est vide | « L'application ne déclare aucune forme. » | rien |
| L'app déclare plus de 32 champs | « L'application déclare 40 champs ; le Core en accepte 32. » | rien |

### 5.3 Les clients

| Cas | Ce que l'opérateur voit | Ce que le Core fait |
|---|---|---|
| **Un seul client** (le cas de l'auto-école qui démarre) | « 1 client de l'application » → « Créer le client » | un client créé, `customer.created` poussé, l'app **lie** son auto-école |
| Aucun client | « L'application n'a encore aucun client. » | rien |
| Le client existe déjà au Core | « 1 client est déjà connu. » | mis à jour, jamais dupliqué |
| Le client existe dans l'app **et** au Core | « déjà connu » | l'app le lie au lieu d'en créer un second |
| E-mail absent ou invalide | refus nommé, ligne par ligne | ligne refusée, les autres créées |
| E-mail déjà pris par un autre client | refus nommé | ligne refusée |
| Code hors du motif de référence | refus nommé (« la référence ne respecte pas le format en vigueur ») | ligne refusée |
| Nom vide | refus nommé | ligne refusée |
| Une valeur déclarée d'un mauvais type | refus nommé | ligne refusée |
| Dizaines de clients | l'aperçu compte ; la création se fait par lots ; la page reste légère | tous les clients traités, un rapport par lot |

### 5.4 Le quotidien, après le branchement

| Cas | Ce qui se passe |
|---|---|
| L'opérateur crée un client dans la console du Core | `customer.created` part vers l'application ; l'app la provisionne (auto-école + administrateur) |
| L'application crée un tenant | **rien** — le Core reste le seul écrivain du commerce (ADR-0107 §5) ; l'opérateur crée le client dans la console |
| Rotation du secret du point d'entrée | pendant le recouvrement, le Core signe avec le secret courant et l'application accepte aussi l'ancien — le même mécanisme que les événements |
| L'application est en staging, le Core en production | chaque environnement a sa clé et son secret ; une clé de staging ne vaut rien en production |

## 6. La sécurité, en cinq points

1. **L'URL** est celle d'un point d'entrée que l'opérateur a enregistrée :
   c'est un opérateur de confiance dans son organisation. Le Core se protège
   quand même : HTTPS obligatoire, **pas de redirection**, délai de 5 s,
   réponse plafonnée à 1 Mo, et l'URL n'est utilisée que pour le projet qui
   l'a déclarée.
2. **Le secret** ne sort jamais du Core : il est chiffré au repos, lu en
   clair au moment de signer, jamais journalisé, jamais rendu à l'écran.
3. **La réponse de l'application est une donnée non fiable** : elle est
   validée par les mêmes règles qu'une proposition reçue par l'API. Une
   application qui ment ne peut pas faire écrire n'importe quoi au Core.
4. **Le corps des réponses n'est jamais journalisé** : il porte des noms, des
   e-mails et des téléphones.
5. **Le rejeu est sans risque** : la référence est unique par projet ; un
   deuxième passage ne fait que mettre à jour.

## 7. Le travail, morceau par morceau

### 7.1 Le paquet `core-adapter` (v1.7.0)

| Fichier | Ce qu'il porte |
|---|---|
| `routes/core.php` | `GET /core/tenant-schema`, `POST /core/tenants` (middleware `VerifyCoreReadSignature`) |
| `src/Laravel/Http/ShowTenantSchemaController.php` | rend la déclaration (clé unique, motif, champs) |
| `src/Laravel/Http/ListTenantsController.php` | rend les locataires, **paginés** (page dans le corps), avec les valeurs déclarées |
| `src/Laravel/Middleware/VerifyCoreReadSignature.php` | accepte les GET (corps vide, signature sur `timestamp.`) comme les POST (corps signé), **et** le secret précédent pendant un recouvrement |
| `docs/locataire.md` | la section « Vos locataires existent déjà » est réécrite : plus de CSV, la console du Core s'en charge |

L'application n'écrit **aucune** ligne pour ce qui relève du branchement : le
paquet sert ses deux routes à partir des contrats qu'elle implémente déjà
(`TenantDeclaration`, `TenantLister`).

**Une** modification dans l'application : `LocataireAutoEcole::tenants()` rend
aussi les trois valeurs déclarées (`localisation`, `nom_respo`,
`annee_creation`) — sans elles, le Core ne saurait rien de ces clients.

### 7.2 Le Core

**Où ça vit : dans la coquille (`app/`), pas dans `packages/billing`.** Le
module `billing` ne peut pas dépendre de `webhook` (G3c — graphe dans
`tests/Architecture/ModuleGraph.php`), et c'est `webhook` qui détient le
point d'entrée **et son secret**. L'interrogation a besoin des deux : elle vit
dans la coquille, qui a le droit de dépendre de tous les modules. **Le graphe
des modules n'est pas modifié** — pas d'arête nouvelle, pas d'ADR de comité.

| Couche | Ce qu'il y porte |
|---|---|
| `app/Billing/ClientApplication/Contracts/` | `ReadsClientApplication` (contrat de lecture : la forme, les clients tirés) |
| `app/Billing/ClientApplication/` | `ResolveClientApplicationTarget` (le point d'entrée retenu, l'adresse), les deux adaptateurs HTTP (`HttpTransport` + `HmacSha256RequestSigner` + une `SignaturePolicy` — **G6c** : la seule sortie HTTP du Core), `ProposeTenantFormFromApplication` (valide, enregistre la proposition), `ImportTenantsFromApplication` (réutilise le chemin de l'import) |
| `app/Billing/ClientApplication/ClientApplicationConnections.php` | le port du branchement retenu (point d'entrée + adresse corrigée) |
| `app/Infrastructure/DatabaseClientApplicationConnections.php` | la table `client_application_connections` (une ligne par projet) |
| `app/Http/Controllers/Admin/` | `BillingClientApplicationController` (la page d'état), les actions « récupérer la forme » et « créer les clients » |
| `resources/views/admin/billing/customers/` | les vues : l'état, l'aperçu de la forme, l'aperçu des clients, le rapport |
| `routes/admin.php` | `projects.customers.application`, et les actions (sensibles → `throttle:console-sensitive`) |

Le **contrat d'écriture est réutilisé, pas réécrit** : `UpsertCustomer` et le
service de proposition de forme sont ceux d'aujourd'hui. C'est ce qui fait
que le CSV et la liste de l'application ne peuvent pas diverger.

### 7.3 L'application

| Fichier | Ce qui change |
|---|---|
| `app/Support/LocataireAutoEcole.php` | `tenants()` rend les valeurs déclarées |
| `composer.json` | `wilfreidlando/core-adapter: ^1.7` |
| `docs/BRANCHER-AU-CORE.md` | le parcours « brancher » sans commande ni fichier |

## 8. La séquence

| # | Morceau | État |
|---|---|---|
| 1 | **ADR-0133** — les décisions (le Core interroge ; le secret du point d'entrée ; l'adresse déduite ; le CSV reste en second plan). | **fait** |
| 2 | **Paquet `core-adapter` v1.7.0** — les deux routes, la pagination, la vérification de signature, les tests, la doc. | **fait** — publié (`v1.7.0`, `a840984`) |
| 3 | **Core — sans console** — les contrats, les adaptateurs, les deux cas d'usage, les tests (le contrat du paquet est simulé par un faux). | **fait** — `2978990` |
| 4 | **Core — console** — la page d'état, les deux actions, l'aperçu, le rapport, les tests d'écran. | **fait** — `2978990` |
| 5 | **Application** — `tenants()`, mise à jour du paquet, tests. | **fait** — `54c4daa` (dépôt de l'application) |
| 6 | **Recette** — sur le staging : une application de démonstration (une auto-école) branchée de bout en bout, avec un client existant, un client déjà connu et un client refusé. | **à faire** — demande un déploiement explicite |
| 7 | **Documentation** — ADR-0133, le parcours `F14`, le guide `docs/integration/brancher-une-application-cliente.md`, le README du paquet. | **fait** |

## 9. Ce que ce plan ne change pas

- Le Core reste **le seul écrivain** de l'état commercial (ADR-0107 §5) :
  l'application ne crée jamais un client chez le Core.
- La charge utile d'un événement **ne porte aucune donnée personnelle**
  (ADR-0070 §3) : l'application relit le client chez le Core.
- L'audit **ne porte aucune valeur** (ADR-0083 §4) : il compte les lignes.
- Les clients sont conservés **en clair** (ADR-0123) ; les valeurs déclarées
  aussi.
- L'import en lot **reste possible** (ADR-0132) : c'est le chemin de secours,
  quand l'application est injoignable ou pour un lot hors bande. Il cesse
  d'être le chemin par défaut.

## 10. La mesure de la réussite

Un projet neuf, une application qui a **un** client existant, un opérateur
qui n'a jamais vu la console du Core :

- il ne tape **aucune** commande ;
- il ne fabrique **aucun** fichier ;
- il ne lit **aucun** en-tête de colonne ;
- il clique, il lit, il clique ;
- à la fin, le client existe dans les deux systèmes, liés, sans doublon.
