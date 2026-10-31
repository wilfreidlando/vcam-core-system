# ADR-0108 — Le kit client devient un paquet Composer

- **Statut** : Accepté
- **Date** : 2026-10-31
- **Décideurs** : commanditaire (« je te laisse prendre vraiment les meilleures décisions » ; « ça doit vraiment être flexible car l'auto-école n'est qu'un exemple de client »), comité d'architecture
- **Dérives concernées** : D1 périmètre · D5 contrat
- **Précise** : ADR-0097 §2 (kit Laravel), ADR-0075 (portail), ADR-0084 (le locataire du SaaS est un client du Core)

## Contexte

Le kit client est aujourd'hui un **copier-coller** : `docs/integration/kit-laravel/`,
16 fichiers que chaque application cliente recopie dans son propre `app/Core/`.

Ce mode a trois défauts, tous observés :

1. **Aucune version.** Un correctif du kit n'atteint les applications clientes que
   lorsque quelqu'un pense à le recopier. La dérive est invisible : rien ne dit, à un
   moment donné, quelle version du kit une application utilise.
2. **Du commerce inutile.** Le kit expose `provision()` (création d'un client),
   `plan_code`, et six appels de commerce. Or le **portail** permet déjà au propriétaire
   de gérer son offre, son abonnement, ses factures et ses paiements. L'application
   cliente fait donc une intégration d'API considérable pour une chose que le portail
   fait déjà — et les trois bogues du kit (G3, G4, G9 de la reconnaissance) naissent
   tous de là.
3. **Un test de contrat qui ne voyage pas.** `tests/Integration/IntegrationKitTest.php`
   prouve le kit contre un **vrai Core**, sans mock entre les deux — c'est la meilleure
   preuve du dépôt. Mais il vit dans le dépôt du Core : un client qui copie le kit
   n'emporte pas la preuve.

Le commanditaire a tranché la direction : le commerce sort du kit, l'application cliente
ne fait pas de commerce, et le kit doit s'installer avec **les seules clés** et appeler
les points d'entrée. Il a ajouté une exigence de **flexibilité** : l'auto-école n'est
qu'un premier client, d'autres arrivent et doivent se brancher aussi facilement.

## Décision

**Décision 1 — le kit devient un paquet Composer `vcam/core-client`.**

Il n'est plus copié : il est installé. Le namespace passe de `App\Core\` à
`Vcam\CoreClient\` — distinct de la racine `Core\` du serveur, pour que le paquet et le
Core puissent coexister dans un même autoloader (ce que le test de contrat exige déjà
aujourd'hui, d'où le `App\Core\` de fortune).

**Décision 2 — la source du paquet vit dans le dépôt du Core, hors de `packages/`.**

`packages/` est l'espace des **modules serveur** : `ModuleGraph.php` en prend les
répertoires comme source de vérité, Deptrac y associe une couche, et
`ComposerGraphTest` vérifie que les deux concordent. Un kit client n'a rien à faire
dans le graphe des modules : il n'a ni domaine, ni application, ni infrastructure — il
est le **client** du Core, pas un de ses modules.

La source est donc dans `client-kit/`, à la racine du dépôt, avec son propre
`composer.json`. Le dépôt du Core reste la **source unique**.

**Décision 3 — la distribution passe par un dépôt dédié, généré.**

Composer n'extrait un paquet que de la **racine** d'un dépôt VCS : un paquet logé dans
un sous-répertoire n'est pas trouvé, que le dépôt ait un `composer.json` racine ou non
(les deux cas ont été éprouvés). Un paquet client ne peut donc pas être installé
depuis le dépôt du Core — il lui faut **son propre dépôt**, où il est à la racine.

Ce dépôt est **généré** : `git subtree split --prefix client-kit` produit un dépôt dont
la racine *est* le paquet, avec son historique. Il n'y a donc jamais deux versions
divergentes : le Core est la source, le dépôt du paquet est un extrait. Une commande
`make kit-release` publie la version.

Tant qu'aucun consommateur hors de VCam n'existe, le paquet est consommé en `path`
(développement) ou via le dépôt généré (déploiement).

**Décision 4 — le noyau ne dépend pas de Laravel.**

`client-kit/src/Core/` ne contient **aucun** `use Illuminate` : le client HTTP, le
mapping des erreurs RFC-7807, la vérification de signature, la politique d'idempotence,
les DTOs. `client-kit/src/Laravel/` contient le collage framework : service provider
auto-découvert, implémentation du transport sur `Illuminate\Http\Client`, middleware,
contrôleur de webhook, événements, configuration publiable.

Raison : le test de contrat doit pouvoir s'exécuter **contre un vrai Core sans
dépendre du framework du test**. Un kit qui ne peut être éprouvé que dans Laravel
n'est pas éprouvé. Et l'exigence de **flexibilité** du commanditaire — d'autres clients
arrivent, pas tous en Laravel — rend cette frontière payée.

Le noyau a **zéro dépendance runtime** : le transport par défaut est `curl`, l'adaptateur
Laravel fournit la sienne. Le paquet s'installe sans traîner quoi que ce soit.

**Décision 5 — la surface du paquet est minimale, et catégorisée par service.**

Toujours présent, parce que c'est le minimum vital d'une application cliente branchée :

| Service | Appels |
| --- | --- |
| `entitlements` | `GET /v1/customers/{ref}/entitlements` — l'application lit ses droits |
| `usage` | `POST /v1/usage` — l'application compte son usage |
| `portal` | `POST /v1/portal/sessions` — l'application ouvre l'espace de gestion |
| `webhooks` | réception, vérification de signature, dédoublonnage |

Au choix, service par service, selon ce que l'application fait :

| Service | Pour |
| --- | --- |
| `notifications` | les applications qui envoient des WhatsApp |
| `payments` | les applications qui encaissent |
| `wallets` | les applications qui gèrent un portefeuille (e-tourisme) |

**Ce qui n'est plus dans le paquet** : le commerce (clients, offres, abonnements,
factures), l'enregistrement des points d'entrée webhook (il se fait **depuis le Core**),
et l'administration.

**Décision 6 — l'installation se résume à trois clés, et une commande les vérifie.**

```
composer require vcam/core-client
# .env : CORE_BASE_URL, CORE_API_KEY, CORE_WEBHOOK_SECRET
php artisan core:check
```

`core:check` vérifie que la base est joignable, que la clé est valide, que les portées
nécessaires sont présentes, et que le secret de webhook est configuré — et dit ce qui
manque. C'est le critère du commanditaire : *installer le kit, mettre les clés, appeler
les points d'entrée*.

**Décision 7 — le test de contrat reste dans la CI du Core, et embarque la fixture G10.**

Le test de contrat (le kit contre un vrai Core, sans mock) reste dans le dépôt du Core :
c'est le seul endroit où un vrai Core est garanti. Il charge le paquet depuis
`client-kit/` (mappé en `autoload-dev`), donc il prouve **la source**, pas une copie.

La fixture `tests/Contract/ConsumerContractFixture.php` — les points d'entrée et les
événements épinglés par le garde-fou **G10** — devient **la déclaration de contrat du
paquet** : elle est embarquée, et vérifiée à la fois contre le document OpenAPI du Core
et contre le Core vivant. Une rupture de l'API qui touche le paquet est vue par le Core
le jour même, pas à la prochaine version du client.

## Alternatives écartées

**Un dépôt séparé comme source unique (le kit vit ailleurs, le Core le consomme).** —
Rejeté : le test de contrat devrait viser un Core **déployé** (secret en CI, staging
en panne = CI bloquée), et la dérive redevient possible — on peut publier un kit pour
une version du Core qui n'existe pas. Le dépôt généré (décision 3) donne la légèreté
sans la dérive.

**Rester un copier-coller, mieux documenté.** — Rejeté : c'est le statu quo. Les
corrections ne parviennent pas aux clients, et rien ne prouve quelle version chaque
application utilise.

**Composer `path` en production.** — Rejeté : le `composer.lock` enregistre un chemin
local ; le déploiement de l'application cliente exigerait le dépôt du Core à côté.
Acceptable en développement, pas en production.

**Un noyau PSR-18 strict.** — Rejeté pour l'instant : la cérémonie PSR (trois
interfaces, une découverte) ne sert qu'un client non-Laravel qui n'existe pas encore.
La frontière nette sans `Illuminate` atteint le même résultat vérifiable — le noyau est
testable seul — sans l'abstraction gratuite.

**Exposer toute la surface du Core dans le paquet.** — Rejeté : la gouvernance
anti-dérive du Core s'applique aussi au client. Chaque point d'entrée publié doit être
requis par un consommateur réel ; le reste est de la surface publique qu'on ne peut plus
casser sans version majeure.

## Conséquences

**Ce qui est gagné.** — L'application cliente s'installe en une commande et se configure
avec trois clés. Les corrections parviennent par `composer update`. La preuve que le
paquet marche contre un vrai Core voyage avec lui. Et l'application cliente n'a plus à
gérer le commerce : elle **reçoit** son locataire, **lit** ses droits, **compte** son
usage, **ouvre** le portail.

**Ce qui est engagé.**

- Une classe ajoutée au paquet est un **contrat public** : elle ne peut plus être
  retirée sans version majeure. D'où la décision 5 : on n'expose que le nécessaire.
- La version du paquet et celle du Core sont **liées**. Le paquet déclare la plage de
  versions du Core qu'il supporte, et le test de contrat le vérifie.
- Le dépôt du paquet est **généré** : on ne modifie jamais le dépôt généré, on modifie
  la source et on régénère. Un `make kit-release` mal utilisé pourrait publier un
  extrait obsolète ; la commande le refuse si l'arbre de travail n'est pas propre.
- Le paquet n'assume pas le **CSRF** : l'application cliente API-only n'a pas de groupe
  `web` (constaté sur l'auto-école : `bootstrap/app.php` n'enregistre que `api`). Le
  point d'entrée webhook est donc exempté de CSRF par construction, et la vérification
  repose sur la **signature**, pas sur un secret partagé.

**Ce qui change pour l'auto-école** (le premier client, dont le modèle est déjà le bon) :
le tenant y est **déjà poussé** par la plateforme (`POST /api/v1/tenants`) et il n'existe
**aucun** écran plan, abonnement ou facture — donc rien à retirer, seulement à
remplacer. Son point d'entrée, protégé aujourd'hui par une clé partagée `X-API-Key`,
sans signature ni anti-rejeu, devient la réception du push signé du Core. Le reste
change peu : une seule porte de sortie HTTP existe déjà (`ClientCoreSystem`), et le
paquet la remplace par la sienne.
