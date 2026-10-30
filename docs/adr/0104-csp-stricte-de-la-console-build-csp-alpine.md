# ADR-0104 — CSP stricte de la console : le build CSP d'Alpine, et rien d'autre dans `script-src`

- **Statut** : Accepté
- **Date** : 2026-10-30
- **Décideurs** : comité d'architecture (dérives D1 périmètre · D5 contrat)
- **Dérives concernées** : D1 périmètre · D5 contrat
- **Remplace** : ADR-0102

## Contexte

La recette navigateur du 2026-10-29 (registre `docs/audit/ecarts-recette-2026-10-29.md`,
F-04 et F-05 ; annexe sécurité n° 1) a établi que la console opérateur ne servait aucun
en-tête de sécurité et chargeait Tailwind et Alpine depuis deux CDN publics. Le
correctif du 2026-10-29 a ajouté le middleware `AdminSecurityHeaders` et auto-hébergé
les deux bibliothèques dans `public/assets/admin/vendor/`, empreintes SHA-256 contrôlées
par `AdminVendoredAssetsTest`.

Il restait deux marqueurs dans `script-src` :

```
script-src 'self' 'unsafe-inline' 'unsafe-eval'
```

**`'unsafe-inline'`** a été retiré le 2026-10-30 : le script inline du layout (l'état du
tiroir mobile) est devenu `public/assets/admin/console.js`, un fichier servi par
l'application, et plus aucune vue de la console ne porte de gestionnaire `on*=` en
attribut.

**`'unsafe-eval'`** restait, et ne pouvait pas partir seul : le build *standard* d'Alpine
évalue les expressions des gabarits avec `new Function`. Le build *CSP* d'Alpine
(`@alpinejs/csp`) ne le fait pas — il **lit** les expressions au lieu de les évaluer —
mais il ne sait lire qu'un **chemin de propriétés**. Une expression qu'il ne sait pas
lire n'est pas une erreur : l'évaluateur se contente d'avertir dans la console du
navigateur, et le comportement disparaît. C'est le symptôme exact de F-01 (un tiroir
inert), déjà payé une fois.

Le lot était donc un travail de fond, pas un interrupteur — et ADR-0102 en faisait un
préalable à la mise en production, non une amélioration différée.

## Décision

**Décision 1 — la console utilise le build CSP d'Alpine, auto-hébergé et épinglé.**

`public/assets/admin/vendor/alpine-csp-3.14.1.min.js` est le fichier
`https://unpkg.com/@alpinejs/csp@3.14.1/dist/cdn.min.js`, vérifié octet pour octet,
empreinte SHA-256 `40f00d7aaa543eabd17c3a0f79ce2ac62e6be17caa9564527c20ec39152bb157`
contrôlée par `AdminVendoredAssetsTest`. La source externe a été **nommée et approuvée**
par le commanditaire, comme l'exigeait la décision 3 bis d'ADR-0102.

Le build **standard** est **retiré** de `public/` : il ne doit pas rester téléchargeable,
pour que personne ne puisse le remettre à la place du build CSP.

**Décision 2 — `script-src` ne contient plus que `'self'`.**

```
script-src 'self'
```

Plus aucun `unsafe-*`. Un script ne peut donc venir que de notre propre origine, et ne
peut être qu'un des fichiers que l'application sert. La CSP est vérifiée par
`AdminSecurityHeadersTest`.

**Décision 3 — l'état de la console vit dans des composants `Alpine.data()`, l'état
d'une vue est un chemin de propriétés.**

Les expressions des gabarits sont désormais, sans exception, des chemins :
`navOpen`, `ariaExpanded`, `label`, `all`. Ce qui ne peut pas s'exprimer ainsi est porté
par un **getter** du composant (`ariaExpanded` rend `'true'` ou `'false'`,
`navOpenClass` rend `'is-open'` ou `''`) — le build CSP lit un getter comme n'importe
quelle propriété.

Les trois constructions que le build standard acceptait et qu'il ne lit plus sont
interdites dans les vues :

| Construction | Remplacée par |
|---|---|
| `x-data="{ open: false }"` (littéral) | un composant enregistré : `x-data="confirmDialog"` |
| `@click="open = ! open"` (affectation) | une méthode : `@click="toggle"` |
| `:aria-expanded="open ? 'true' : 'false'"` (ternaire) | un getter : `:aria-expanded="ariaExpanded"` |

**Décision 4 — `x-model` est remplacé par l'état initial rendu par le serveur, et un
écouteur `@change`.**

Le build CSP ne sait pas affecter (`x-model` fait `expression = __placeholder`), et
`x-model` posait en plus l'état **coché** des cases depuis le navigateur. Cet état est
un fait serveur : il est donc rendu par le serveur.

- un bouton radio porte `@checked(old('template', 'server') === $value)` et
  `@change="selectTemplate"` ;
- une case à cocher porte `@checked($isAll)` et `@change="toggleAll"` ;
- l'état initial d'un composant vient d'un attribut de données (`data-all`,
  `data-template`) lu par `init()`.

Le comportement est le même — y compris après un échec de validation, où la valeur
renvoyée par `old()` est la valeur choisie — mais il ne dépend plus du navigateur.

**Décision 5 — l'état d'un composant est à plat, et `this` y est le composant.**

Le build CSP lie `this` à l'objet qui **possède** la propriété, et seulement s'il est
**directement** dans la pile de portée. Un état imbriqué (`adminShell.nav.open`)
résoudrait `this` vers la portée fusionnée, où `open` n'existe pas : l'écriture
porterait sur l'objet racine au lieu de l'état. Tous les composants gardent donc leur
état à plat (`navOpen`, `expanded`, `showDetails`, `editing`, `all`, `template`).

**Décision 6 — le store `nav` devient le composant `adminShell`, sur `<body>`.**

Un store ne fonctionne pas avec ce build : `this` n'y est jamais lié à l'objet du
store, `toggle()` lirait `undefined`. L'état du tiroir est donc porté par un composant
`adminShell` posé sur `<body>` — le seul ancêtre commun du hamburger (en-tête) et du
tiroir (barre latérale).

**Décision 7 — le garde-fou `ConsoleCspExpressionsTest`.**

Le test lit toutes les vues de la console et refuse :
- une expression qui n'est pas un chemin de propriétés ;
- un `x-model` ou un `x-init` ;
- un `x-data` qui ne nomme pas un composant enregistré dans `console.js` ;
- un composant enregistré que aucune vue ne demande.

C'est la seule façon de tenir la règle : une expression illisible ne casse aucun test
PHP, elle disparaît. Le garde a été prouvé mordant en lui injectant une affectation,
un littéral et une chaîne logique — les trois sont refusées.

**Décision 8 — Tailwind reste compilé à l'exécution, et c'est acté.**

`tailwindcss-3.4.16.js` est le Play CDN : il compile le CSS dans le navigateur, et
Tailwind écrit lui-même que ce build n'est pas destiné à la production. Le compiler
(CLI `tailwindcss`, sortie dans `public/assets/admin/`) supprimerait ce build au prix
d'un outillage Node dans l'image de build. La décision reste celle d'ADR-0102
(décision 5), renvoyée à un ADR d'outillage distinct. Le fichier n'emploie ni `eval`
ni `new Function` : il ne pose aucun problème à la CSP.

## Alternatives écartées

**Garder `'unsafe-eval'`.** — Rejeté : c'est l'état que la mise en production
conditionnait. `'unsafe-eval'` ne se limite pas à l'exécution d'un script injecté : il
autorise `eval()` dans la page, donc dans toute injection de contenu. La console
affiche des clés API en clair et porte le jeton CSRF de l'opérateur : ce n'est pas un
état acceptable pour un produit de production.

**Retirer Alpine et réécrire l'interactivité en JavaScript natif.** — Rejeté pour ce
lot, et **la question reste ouverte** : il atteint le même résultat de CSP
(`script-src 'self'`, zéro JavaScript tiers) au prix d'une réécriture de ~225 lignes —
tiroir, piège de focus des modales, bascules, cases, radios — et d'un état par instance
à gérer à la main. Le build CSP d'Alpine atteint le même résultat pour un dixième du
travail, et le maintien d'Alpine ne coûte aucune surface d'attaque supplémentaire : le
fichier vendorisé est épinglé par son empreinte, servi de notre origine, et sans
`eval`. Si la console grossit (composants RichText, virtualisation, directives d'un
plugin), la question sera posée de nouveau.

**Charger le build CSP depuis `unpkg.com` avec `integrity` + `crossorigin`.** —
Rejeté : `script-src 'self'` devrait alors autoriser l'hôte du CDN, ce qui rouvre
exactement F-05 — un service tiers compromis exécuterait du code sur l'origine de la
console. `integrity` protège le *fichier*, pas le *service*.

**Garder le build standard et neutraliser `eval` autrement.** — Rejeté : sans
`unsafe-eval`, `new Function` lève une exception et le tiroir, les modales et les
bascules cessent de fonctionner.

## Conséquences

**Ce qui est fermé** : plus aucun `eval()` ni `new Function()` n'est possible dans une
page de la console. La CSP est `script-src 'self'` — un seul hôte de script possible,
le nôtre.

**Ce qui est plus difficile** : le build CSP est moins capable que le build standard.
`x-model`, les littéraux, les ternaires, les appels et les plugins `x-collapse`,
`x-trap`, `x-focus`, `x-intersect`, `x-mask` (absents du build) n'y sont pas. La
console n'en utilise aucun aujourd'hui ; un besoin à l'avenir est une raison de
revoir ce choix.

**Ce qui est engagé** :
- la console doit être **recettée dans un vrai navigateur** avant la mise en
  production : tiroir mobile, modales de confirmation (ouverture, piège de focus,
  `Échap`, focus rendu au déclencheur), bascules d'audit et de livraison, cases à
  cocher, radios de gabarit de clé ;
- `ConsoleCspExpressionsTest` garde la migration : une expression illisible ne peut
  plus revenir sans que le build le dise ;
- `AdminVendoredAssetsTest` garde les empreintes et l'absence du build standard.

**Point de réévaluation** : si la console doit utiliser une directive absente du build
CSP, l'arbitrage se repose — passer en JavaScript natif, ou assumer `unsafe-eval` avec
la justification écrite qui va avec.
