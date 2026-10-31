# ADR-0112 — Le paquet client s'appelle `core-adapter`

- **Statut** : Accepté
- **Date** : 2026-10-31
- **Décideurs** : commanditaire (il a imposé le nom), comité d'architecture
- **Dérives concernées** : D7 organisationnelle
- **Précise** : ADR-0109 (décision 3 — le nom du dépôt), ADR-0108 (le paquet), ADR-0107

## Contexte

ADR-0109 avait publié le paquet client dans le dépôt `saas-commerce-dep`, un nom
qui dit **ce que le paquet ne fait pas**. Or le paquet ne fait pas de commerce :
il n'expose ni clients, ni abonnements, ni factures — le commerce est un **service**
que le paquet consomme comme un autre (notifications, paiements, portefeuilles,
portail). Le nom affirmait donc une absence.

Le commanditaire l'a dit : « il doit avoir le nom `coreAdapter` car il ne fait pas
que le e-commerce ». Un paquet qui se présente comme un adaptateur du Core est
exactement ce qu'il est : un client, un adaptateur Laravel, et rien de plus.

## Décision

**Décision 1 — le paquet s'appelle `core-adapter`, l'espace de noms `CoreAdapter\`.**

Le nom Composer est `wilfreidlando/core-adapter`, l'espace de noms est
`CoreAdapter\`, le fournisseur Laravel découvert automatiquement est
`CoreAdapter\Laravel\CoreClientServiceProvider`. Les classes gardent le mot
« Core » quand elles nomment **ce dont elles parlent** (`CoreClient`,
`CoreConfig`, `CoreException`, `CoreEventReceived`, `CoreWebhookController`) :
elles nomment le système distant, pas le paquet. `LaravelTransport` garde
« Laravel » : elle nomme le **framework** qu'elle enveloppe.

**Décision 2 — l'arbre est aplati : le noyau n'est plus dans un sous-dossier.**

`src/Core/**` devient la racine de `src/`, `src/Laravel/**` reste en place. Un
paquet dont le noyau est dans `Core/` et l'adaptateur dans `Laravel/` se lit
comme deux paquets ; à plat, la règle se dit d'un trait : **tout `src/` est
le noyau, sauf `src/Laravel/`, qui est la colle**. Le noyau ne dépend de rien
(« zéro dépendance à l'exécution », `php` seul) ; la colle est le seul endroit
qui parle Laravel.

**Décision 3 — l'identité quitte aussi les chaînes d'exécution.**

Trois chaînes en mémoire et un nom de route portaient l'ancienne identité :
le préfixe de cache `saas_commerce:entitlements:`, le préfixe de cache
`saas_commerce:event:`, l'attribut de requête `saas_commerce.core_event`, et le
nom de route `saas-commerce.webhooks.core`. Ils deviennent `core_adapter:…` et
`core-adapter.webhooks.core`. Un paquet à moitié renommé est pire qu'un paquet
non renommé : la rupture est **complète et annoncée**, jamais miette.

**Décision 4 — le contrat du paquet ne change pas.**

Ce qui fait le paquet reste exactement ce qu'il était : le noyau sans
framework, l'adaptateur Laravel, l'absence de commerce (vérifiée par
`ForbiddenMethods` et `NoCommerceTest` par réflexion), l'idempotence portée par
le corps et non par un en-tête, et `Portal::open()` qui n'envoie jamais
`customer` ni `plan_code`. Le renommage ne touche **aucun** de ces points.

**Décision 5 — le dépôt GitHub porte encore l'ancien nom, et c'est au
commanditaire de le renommer.**

Le dépôt distant s'appelle `wilfreidlando/saas-commerce-dep` ; le paquet
s'appelle `core-adapter`. L'URL VCS du `composer.json` pointe déjà vers le nom
nouveau : elle ne résoudra pas tant que le dépôt GitHub n'aura pas été
renommé (`Paramètres → Général → Nom du dépôt`). La machine du commanditaire
n'a pas d'outil GitHub (`gh` n'y est pas installé) : renommer un dépôt est un
geste de propriétaire, pas une commande.

## Alternatives écartées

**Garder `saas-commerce-dep` et expliquer le sens dans le README.** — Rejeté :
un nom se lit dans une ligne de `composer.json`, dans un `use`, dans un
`composer require`. Il doit dire ce que la chose **est**, pas ce qu'elle
n'est pas.

**`core-client` (l'appellation d'ADR-0108).** — Rejeté : le paquet ne se
contient pas lui-même ; il contient un **client** du Core et son **adaptateur**
Laravel. « Adapter » dit les deux, et dit la seule chose qui compte pour
l'utilisateur du paquet : il s'insère dans son application.

**Renommer le dépôt GitHub tout seul.** — Rejeté : le compte du commanditaire
n'a pas d'outil d'administration GitHub sur cette machine. La décision est
prise ici, l'acte reste au propriétaire.

**Renommer les classes `Core*`.** — Rejeté : `CoreClient` est le client **du
Core**, pas le client du paquet. Renommer serait une faute de vocabulaire.

## Conséquences

**Ce qui est gagné.** — Le paquet porte le nom de ce qu'il fait, et l'arbre
correspond au nom : la règle « tout `src/` est le noyau sauf `src/Laravel/` »
est lisible sans connaître l'historique.

**Ce qui est engagé.**

- Le dépôt GitHub doit être renommé par le commanditaire, puis l'URL distante
  mise à jour (`git remote set-url origin …/core-adapter.git`). Tant que ce
  n'est pas fait, `composer require wilfreidlando/core-adapter` ne résout pas.
- Les quatre chaînes renommées (deux préfixes de cache, un attribut de
  requête, un nom de route) sont une **rupture** pour un consommateur qui les
  aurait écrites en dur. Le paquet n'a jamais été publié (`^1.0`, hors
  Packagist) : il n'y a donc aucun état déployé à migrer.
- Le Core conserve une **copie locale** du paquet (`client-kit/`, non suivie
  par Git) : elle doit être resynchronisée depuis la source renommée avant
  d'être réinjectée.

**Point de réévaluation.** — Si le paquet gagne un jour une capacité de
commerce — ce que le présent document refuse — le nom devra changer encore.
C'est le dernier renommage qu'il faudra.
