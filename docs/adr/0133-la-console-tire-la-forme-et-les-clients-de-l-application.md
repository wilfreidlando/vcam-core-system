# ADR-0133 — La console tire la forme et les clients de l'application

- **Statut** : Accepté (le code est livré et vérifié ; la décision suit le code)
- **Date** : 2026-11-22
- **Décideurs** : commanditaire (« je veux des actions dans la console core […] tout via le core […] prend tout les cas le but est que ce soit vraiment simple et le moins technique possible de tous les côtés »), comité d'architecture
- **Dérives concernées** : D4 intégrité des données
- **Précise** : ADR-0122 §2, §5, §6, ADR-0122 §9, ADR-0130 §1, §2, §3, ADR-0132
- **Amende** : ADR-0122 §9 (le fichier importé n'est plus le chemin par défaut)

## Contexte

ADR-0122 a rendu la **forme** du locataire déclarable par l'application ;
ADR-0122 §9 a prévu le cas de l'application qui avait **déjà** des clients :
l'export en CSV, le dépôt du fichier, l'import en lot. ADR-0130 a rendu la
console capable de **décider** de la forme ; ADR-0132 a fait porter au fichier
la forme en vigueur.

Le mécanisme fonctionne. Mais son **usage** demande des gestes techniques, et
c'est là que le mécanisme échoue :

1. **Déclarer la forme** se fait par une commande sur le serveur de
   l'application (`core:declare-tenant-schema`) : l'opérateur de la console
   ne peut pas le faire, et la console ne sait rien de ce qui existe déjà.
2. **Faire connaître les clients existants** se fait par un fichier : la
   commande `core:export-tenants` écrit un en-tête fixe
   (`external_ref,display_name,email,phone`), le fichier est déposé, et
   l'en-tête est refusé dès que la forme déclarée remplace une colonne
   (ADR-0132). L'opérateur doit connaître la forme en vigueur **pour
   fabriquer un fichier que la console sait fabriquer**.
3. Les deux chemins exigent que le secret et la clé d'API soient posés à la
   main dans le `.env` de l'application — un geste hors console, par nature.

Le résultat est mesurable : le geste le plus simple possible — *une
application de gestion d'auto-écoles qui a **un** client et qui veut être
branchée* — demande aujourd'hui une commande, un fichier fabriqué à la main,
un en-tête deviné, un dépôt et un import. La console du Core sait faire tout
le reste ; il lui manque **une seule** chose : **interroger** l'application.

## Décision

**Décision 1 — le Core peut interroger une application cliente.**

Le Core envoie une **lecture** à l'application, sur une route servie par le
paquet `core-adapter` :

- `GET /core/tenant-schema` — la forme que l'application veut ;
- `POST /core/tenants` — les locataires qu'elle a déjà, **paginés**.

La page demandée est portée par le **corps** de la requête, pas par la
chaîne de requête : c'est le corps qui est signé. Un `GET` paginé
(`/core/tenants?page=2`) porterait sa pagination **hors** de la signature —
le secret suffirait à lire, mais pas à prouver quelle page on a demandée.
Le `POST` garde une seule signature pour tout le contenu de la requête.

Ces lectures sont **nouvelles** mais **minérales** : le Core sait déjà
signer une requête sortante (`Core\Support\Integration\Transport\HttpTransport`
+ `HmacSha256RequestSigner`, ADR-0031), et le paquet sait déjà vérifier la
signature d'une requête entrante (`CoreAdapter\Webhooks\Signature`). Il n'y a
rien à inventer.

**Décision 2 — l'adresse de l'application est déduite du point d'entrée.**

L'adresse appelée est celle du point d'entrée déjà enregistré pour le projet
(même schéma, même hôte) : l'opérateur ne saisit pas une seconde adresse.
La console la montre et permet de la corriger, au cas où l'API de
l'application n'est pas sur le même hôte que la réception des événements.

**Décision 3 — l'authentification est le secret du point d'entrée.**

La requête Core → application est signée avec le **secret de signature du
point d'entrée**, selon le schéma déjà en service : `X-Signature =
HMAC-SHA256(timestamp + "." + corps)`, `X-Timestamp`, tolérance de 300 s.
C'est le secret que les deux parties partagent déjà ; aucun secret n'est
créé pour ce flux.

Pendant un recouvrement de secret (ADR-0082 §5), le Core signe avec le
secret courant et l'application accepte **aussi** l'ancien : la rotation ne
casse pas le branchement, comme elle ne casse pas la livraison des événements.

**Décision 4 — ce qui est tiré devient une proposition, ou un rapport.**

- Une forme tirée de l'application est **validée par les mêmes règles** que
  celle reçue par `PUT /v1/projects/{projectId}/tenant-schema`
  (ADR-0122 §6, ADR-0130 §2), puis enregistrée comme **proposition en
  attente**. L'opérateur l'**accepte** ou la **refuse** : la forme décide ce
  que l'opérateur devra saisir pour chaque client, c'est une décision, pas
  une formalité. Une déclaration invalide n'est **pas** enregistrée.
- Une proposition **identique** à une forme déjà connue ne crée pas de
  version de plus (ADR-0130 §3).
- Une liste de clients tirée de l'application est **d'abord un aperçu** :
  combien seront créés, combien sont déjà connus, combien seront refusés et
  pourquoi. La création, ensuite, passe par **exactement** le même chemin
  d'écriture que l'import en lot : le même cas d'usage, le même audit, le
  même événement, les mêmes refus, nom par nom.

**Décision 5 — l'import en lot reste, mais cesse d'être le chemin par défaut.**

L'import en lot (ADR-0122 §9, ADR-0132) reste disponible : c'est le chemin de
secours quand l'application est injoignable, et le chemin hors bande d'un
lot. Il n'est plus le chemin par défaut du branchement.

**Décision 6 — le Core reste le seul écrivain.**

L'application ne crée jamais un client chez le Core (ADR-0107 §5) :
l'interrogation est une **lecture**, du côté du Core. Et le Core ne
continue jamais une lecture par une écriture chez l'application : ce sont les
événements qui portent l'écriture, comme avant.

**Décision 7 — la réponse de l'application est une donnée non fiable.**

Elle est validée par les mêmes règles qu'une proposition reçue par l'API.
Une application qui ment ne peut pas faire écrire n'importe quoi au Core.
Le corps des réponses n'est **jamais journalisé** : il porte des noms, des
e-mails et des téléphones.

**Décision 8 — l'interrogation vit dans la coquille de l'application, pas
dans le module `billing`.**

Le module `billing` ne peut pas dépendre de `webhook` (G3c — graphe dans
`tests/Architecture/ModuleGraph.php`) : or c'est `webhook` qui détient le
point d'entrée et **son secret**. L'interrogation a donc besoin des deux. Elle
vit dans la **coquille** (`app/`), qui a le droit de dépendre de tous les
modules : les contrats de lecture (`ReadsClientApplication`), les deux
adaptateurs, les deux cas d'usage et la page de console y sont. Le graphe des
modules n'est **pas** modifié : pas d'ADR de comité, pas d'arête nouvelle.

**Décision 9 — le branchement retenu est mémorisé.**

Ce que l'opérateur a choisi (le point d'entrée, et l'adresse corrigée
s'il y en a une) est **écrit** dans la table `client_application_connections`
de la coquille — une ligne par projet. Sans cela, l'adresse déduite serait
recalculée à chaque visite et une correction se perdrait. Le point d'entrée
retenu reste consultable même s'il est désactivé ensuite : la page continue
de montrer ce qui a été choisi, avec la raison qu'il ne fonctionne plus.

## Alternatives écartées

- **Laisser l'opérateur déclarer la forme depuis la console, à la main.**
  Rejeté : il recopierait un motif et une liste de champs d'une
  documentation, en risking de diverger de ce que l'application sait
  réellement. La même divergence que celle que l'import en lot portait déjà
  (ADR-0132).
- **Faire porter le transfert par un fichier que le Core va chercher chez
  l'application.** Rejeté : cela déplace le geste, il ne le supprime pas —
  et cela ajoute une route d'écriture chez l'application, donc un second
  protocole, alors qu'un mécanisme unique suffit.
- **Créer un secret dédié au flux Core → application.** Rejeté : un secret de
  moins à copier dans le `.env` de l'application, et un secret de moins à
  faire tourner. Le secret du point d'entrée est déjà partagé, déjà
  chiffré au repos, déjà rotable.
- **Écrire directement chez l'application pendant le rattachement.** Rejeté :
  le Core est le seul écrivain de l'état commercial (ADR-0107 §5) ; une
  écriture supplémentaire ouvrirait un second chemin, avec ses propres
  garanties à tenir.
- **Créer les clients sans aperçu.** Rejeté : une création de masse sans
  prévisualisation transforme une erreur de l'application en vingt clients
  refusés à défaire. L'aperçu coûte un clic et évite le reste.

## Conséquences

- L'application cliente porte, avec le paquet `core-adapter`, les deux
  routes de lecture ; elle n'écrit **aucune** ligne pour le branchement.
- L'application doit exposer ses **valeurs déclarées** dans sa liste de
  locataires (la forme en vigueur n'est pas devinée par le Core).
- La console gagne une page d'état — *« Application cliente »* — et deux
  actions : *« Récupérer la forme de l'application »* et *« Créer les
  clients depuis l'application »*.
- Le retrait des commandes `core:declare-tenant-schema` et
  `core:export-tenants` du parcours par défaut est **une conséquence**, pas
  une décision : les commandes restent dans le paquet tant qu'elles servent
  autre chose que le branchement.
- L'onglet *« Forme du client »* (F14 §9) et l'onglet *« Importer des
  clients »* (F14 §10) sont **remplacés** par la page d'état, dans le guide
  de l'opérateur.

## Vérification

- Test : la console tire la forme de l'application, la valide, et la
  présente comme une proposition en attente ; une déclaration invalide n'est
  pas enregistrée.
- Test : une proposition identique à la forme en vigueur ne crée pas de
  version de plus.
- Test : le client déjà connu chez le Core est mis à jour, jamais dupliqué ;
  un client refusé l'est avec son motif, et les autres sont créés.
- Test : la liste tirée de l'application et le fichier importé empruntent le
  **même** chemin d'écriture — même audit, mêmes événements, mêmes refus.
- Test : la signature de la requête Core → application est refusée si le
  secret diffère ; rien n'est écrit dans ce cas.
- Test : le secret précédent est accepté pendant le recouvrement.
- Test : un client tiré de l'application et créé dans le Core est poussé à
  l'application, qui le **relie** au lieu d'en créer un second.
