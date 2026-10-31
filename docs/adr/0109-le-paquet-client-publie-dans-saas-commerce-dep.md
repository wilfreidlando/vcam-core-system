# ADR-0109 — Le paquet client est publié dans `saas-commerce-dep`, et sa source y vit

- **Statut** : Accepté
- **Date** : 2026-10-31
- **Décideurs** : commanditaire (il a fourni le dépôt), comité d'architecture
- **Dérives concernées** : D1 périmètre · D5 contrat · D7 organisationnelle
- **Précise** : ADR-0108 (décision 3 — le dépôt de distribution), ADR-0107 (le contenu du paquet)

## Contexte

ADR-0108 §3 avait décidé que la **source** du paquet resterait dans le dépôt du Core,
et que le dépôt de distribution serait **généré** par `git subtree split`. Le
commanditaire a fourni le dépôt de distribution : `wilfreidlando/saas-commerce-dep`,
public, et vide.

Un dépôt public change la donne sur un point précis : **un dépôt public reçoit des
correctifs.** Un contributeur qui trouve un bug en lit l'URL, clone, corrige, ouvre
une pull request. Si ce dépôt est un **extrait généré**, cette pull request porte sur
un fichier que la prochaine régénération **écrase** — silencieusement, et le bug
revient. Un dépôt public qui n'est pas la source est un dépôt où les correctifs
disparaissent.

C'est la seule raison qui fait changer la décision. Les autres restent vraies :

- la **preuve** que le paquet marche contre un vrai Core est le bien le plus précieux
  du paquet, et elle a besoin d'un Core ;
- le Core est le **maître** du contrat : c'est lui qui décide de ce qui est publié.

## Décision

**Décision 1 — `wilfreidlando/saas-commerce-dep` est la source du paquet.**

Le dépôt porte le paquet Composer : `composer.json` à sa racine (condition pour que
Composer l'installe — voir ADR-0108 §3), le code, la configuration, la documentation
et sa propre CI. Les correctifs et les pull requests y trouvent leur place.

**Décision 2 — le Core ne dépend pas du dépôt du paquet, pour sa propre chaîne de
build.**

Le dépôt du paquet n'est pas un **dépôt parent** du Core : aucun `checkout` du
paquet dans la CI du Core, aucune dépendance de `composer install`. Une CI qui peut
tomber parce qu'un autre dépôt est indisponible n'est pas une CI de production.

**Décision 3 — chaque côté garde son propre garde-fou, et les deux se répondent.**

| Côté | Garde-fou | Ce qu'il attrape |
|---|---|---|
| **Core** | une fixture de ce dont le paquet a besoin (`tests/Contract/ClientApplicationContractFixture.php` : points d'entrée, événements, portées), confrontée à la table de routage, au catalogue d'événements, au document OpenAPI et aux modèles de portées | une rupture de l'API qui touche le paquet, **le jour même**, sur chaque commit du Core |
| **Paquet** | son test de contrat, exécuté contre un **Core déployé** (staging), dans sa propre CI | un paquet qui marche contre un Core mais pas contre un autre, une régression de la bibliothèque elle-même |

La fixture du Core et le test du paquet sont **la même déclaration** vue des deux
côtés : le Core la garde, le paquet la vérifie.

**Décision 4 — le test de contrat du paquet parle HTTP, pas le kernel.**

Le test interne du Core (`tests/Integration/IntegrationKitTest.php`) prouve le kit
en interceptant les appels et en les reinjectant dans le kernel de l'application —
une commodité interne. Le test du paquet, lui, parle **HTTP** à un Core déployé :
c'est exactement ce que vit un client, et c'est la seule preuve qui vaille pour une
bibliothèque publique.

**Décision 5 — le nom du paquet suit le nom du dépôt.**

| | |
|---|---|
| Dépôt | `github.com/wilfreidlando/saas-commerce-dep` |
| Paquet Composer | `wilfreidlando/saas-commerce-dep` |
| Namespace | `SaasCommerce\` — `SaasCommerce\Core\` (sans framework) et `SaasCommerce\Laravel\` (adaptateur) |

L'installation, côté application cliente :

```json
{
  "require": { "wilfreidlando/saas-commerce-dep": "^1.0" },
  "repositories": [
    { "type": "vcs", "url": "https://github.com/wilfreidlando/saas-commerce-dep.git" }
  ]
}
```

## Alternatives écartées

**Garder la source dans le Core et régénérer le dépôt public à chaque version.** —
Rejeté : les correctifs ouverts sur le dépôt public disparaissent à la
régénération suivante. Un dépôt public doit être la source.

**Faire dépendre la CI du Core du dépôt du paquet.** — Rejeté : la chaîne de build du
Core deviendrait dépendante d'un dépôt qu'elle ne contrôle pas. Le couplage va dans
l'autre sens : c'est le paquet qui se vérifie contre le Core.

**Développer le paquet dans le dépôt du Core, sans dépôt séparé.** — Rejeté : ADR-0108
a établi que Composer n'extrait un paquet que de la racine d'un dépôt VCS. Sans dépôt
dédié, le paquet n'est pas installable.

## Conséquences

**Ce qui est gagné.** — Le paquet est une vraie bibliothèque publique : son dépôt, son
README, ses versions, ses correctifs. Le Core garde la main sur le contrat, et le
paquet garde la preuve.

**Ce qui est engagé.**

- Deux dépôts à faire vivre, et deux CI. Le **Core** ne dépend pas du paquet ; le
  paquet se vérifie **contre** le Core.
- Le test de contrat doit parler **HTTP** : il demande un Core déployé (staging),
  donc un secret en CI du paquet, et il n'est pas instantané comme un test interne.
- Le contrat est déclaré **deux fois** — la fixture côté Core, le test côté paquet.
  C'est le prix de la découplage, et il est payé par une garde-fou : si les deux
  divergent, le paquet échoue contre le Core déployé.
- L'installation d'une application cliente demande une ligne `repositories` (le
  paquet n'est pas sur Packagist) et un accès en lecture au dépôt.

**Ce qui reste ouvert.** — La première version publiée (le tag `v1.0.0`) et son
contenu exact : le socle « noyau sans framework + adaptateur Laravel » décrit par
ADR-0108 §4, réduit à la surface que l'architecture d'ADR-0107 impose.
