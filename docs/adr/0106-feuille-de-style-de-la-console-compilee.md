# ADR-0106 — La feuille de style de la console est compilée dans un fichier

- **Statut** : Accepté
- **Date** : 2026-10-31
- **Décideurs** : commanditaire (priorité « Compiler Tailwind »), comité d'architecture
- **Dérives concernées** : D5 contrat (ce que la console sert, et comment)
- **Amende** : ADR-0104 (décision 8) et ADR-0102 (décision 5), qui renvoyaient ce point à « un ADR d'outillage distinct »

## Contexte

La console opérateur servait Tailwind par le « Play CDN » auto-hébergé
(`public/assets/admin/vendor/tailwindcss-3.4.16.js`). Ce build compile le CSS **dans
le navigateur**, à chaque page, à partir du DOM vivant.

Trois défauts :

1. **440 Ko de script servis** (134 Ko gzip) pour produire 30 Ko de CSS — le script est
   rechargé et réexécuté à chaque navigation.
2. **Un flash de contenu sans style** : le script est asynchrone, la page se peint avant
   que le CSS n'existe.
3. **Tailwind écrit lui-même que ce build n'est pas destiné à la production.**

ADR-0102 (décision 5) puis ADR-0104 (décision 8) ont acté ce point et renvoyé la
décision à un ADR d'outillage distinct, en notant que le compiler coûterait « un
outillage Node dans l'image de build ». C'est cet ADR. La question à trancher est donc
double : *comment* compiler, et *où* Node habite.

Le point délicat est **`rotate-90`**. Le Play CDN lisait le DOM vivant : une classe
posée par le JavaScript existait dans le CSS sans jamais figurer dans un gabarit. Or
`console.js` pose `rotate-90` sur le chevron du détail d'une livraison de webhook, et
`opacity-50` sur les lignes estompées d'un éditeur de point de terminaison. Une
compilation statique ne lit que ses sources : ces deux classes auraient été **perdues
en silence** — aucun test PHP ne les voit, et le symptôme est un chevron qui ne pivote
plus. C'est le même mode de falha que F-01 (un tiroir inerte), déjà payé une fois.

## Décision

**Décision 1 — la console sert une feuille compilée : `public/assets/admin/console.css`.**

Produite par la CLI Tailwind **3.4.16** — la même version que le Play CDN : le jeu de
classes est identique, seul celui qui compile change. La feuille fait 30 859 octets
(6 Ko gzip), pour 471 règles.

Le layout la lie par `<link rel="stylesheet" href="{{ asset('assets/admin/console.css') }}">`.
La CSP de la console (`base-uri 'self'; form-action 'self'; frame-ancestors 'none';
object-src 'none'; script-src 'self'`) ne porte ni `default-src` ni `style-src` : une
feuille de même origine n'y change rien, et le bloc `<style>` inline du layout — le
tiroir mobile, l'anneau de focus, l'animation de message — reste autorisé.

**Décision 2 — la feuille est versionnée avec le code ; Node n'habite pas l'image.**

C'est ce qui rend l'outillage gratuit en production : les images Docker n'ont pas de
Node, et le fichier livré est exactement celui que les sources produisent. `node_modules`
est ignoré par Git (`.gitignore`) et par Docker (`.dockerignore`). Node devient un
outil de développement, comme `composer` — jamais un outil de production.

**Décision 3 — le périmètre de compilation est plus large que les gabarits.**

`tailwind.config.js` scanne `resources/views/**`, `app/**`, `packages/**` et
`public/assets/admin/console.js`.

Le motif est la perte silencieuse : une classe peut venir d'un gabarit, d'un bloc `@php`
d'un composant (`$toneRing`, `$barClass`), d'un contrôleur, ou du JavaScript. Le
périmètre large est la règle ; la liste n'est qu'un détail. Le coût est **mesuré** :
+823 octets (29 806 → 30 629).

**Décision 4 — la porte CI « styles » prouve que la feuille est à jour.**

`npm run check:css` recompile la feuille depuis les sources et la compare au fichier
versionné (`cmp`). Elle échoue si une vue a reçu une classe sans que la feuille soit
recompilée. `make css-check` fait la même chose dans un conteneur `node:22-alpine` :
l'image ne porte pas `git`, la comparaison se fait donc par `cmp` et non par
`git diff` — **le même contrôle** sur l'hôte et dans le conteneur, une seule
définition.

**Décision 5 — `ConsoleStylesheetTest` garde les invariants.**

- la feuille existe, est d'un volume plausible, et est liée par le layout ;
- le layout ne charge plus aucun exécuteur de Tailwind, et le fichier du Play CDN
  n'est plus téléchargeable (pour que personne ne puisse le remettre à sa place) ;
- les utilitaires dont la console dépend sont **dans** la feuille, y compris
  `rotate-90` et `opacity-50`, que seul le JavaScript pose ;
- aucune page ne charge une feuille d'un autre hôte.

**Décision 6 — la version de Tailwind ne change pas.**

3.4.16, celle que la console utilisait. Un changement de version est un changement
d'outillage : il se décide dans un ADR, comme celui-ci.

## Alternatives écartées

**Node dans l'image de build.** — Rejeté : la feuille est versionnée, l'image n'a donc
rien à compiler. L'outillage n'a pas à entrer en production pour que le résultat
arrive en production.

**Un bundler (Vite).** — Rejeté : la console est en Blade, sans JavaScript de build.
Vite ajouterait une chaîne d'outils, un `manifest.json`, une étape de plus — pour un
besoin qui n'existe pas. Tailwind seul suffit.

**Garder le Play CDN et lui ajouter un cache HTTP.** — Rejeté : le CDN reste un
exécuteur de JavaScript de 440 Ko sur l'origine de la console — F-05 reste ouvert sur
le fond, même auto-hébergé — et le flash de contenu sans style demeure.

**Écrire à la main une feuille « des seules classes utilisées ».** — Rejeté : c'est
exactement ce que fait Tailwind. Le réécrire serait reprendre le travail de l'outil
sans le compilateur, et perdre la détection des classes construites.

**Ne scanner que les gabarits.** — Rejeté : c'est la variante qui perd `rotate-90`.
Le surcoût mesuré du périmètre large est de 823 octets ; le prix d'une perte silencieuse
est un bug que rien ne signale.

## Conséquences

**Ce qui est gagné.** — Le script servi tombe de 440 Ko à 30 Ko (134 Ko gzip → 6 Ko
gzip). Plus de flash de contenu sans style : la console peint avec ses styles dès la
première trame. Et la feuille servie est **exactement** celle que les sources
décrivent — ce que le Play CDN garantissait par construction, et qu'une compilation
statique doit obtenir par un garde-fou.

**Ce qui est engagé.**

- Une classe ajoutée à une vue **exige** de recompiler (`make css`) ; la porte CI
  « styles » le refuse sinon. C'est une contrainte de travail réelle, assumée.
- Node devient un prérequis de développement. Le `Makefile` l'enferme dans un
  conteneur (`node:22-alpine`) : l'hôte n'a toujours besoin que de Docker (ADR-0019).
- La feuille versionnée est un **artefact** : deux personnes qui modifient des vues en
  même temps peuvent se marcher dessus sur `console.css`. La porte le signalera ; la
  résolution est un `git checkout` du fichier et une recompilation.

**Ce qui est fermé.** — Le Play CDN ne peut pas revenir : le fichier n'est plus dans
`public/`, `AdminVendoredAssetsTest` et `ConsoleStylesheetTest` le refusent, et la
porte « styles » refuse une feuille absente ou périmée.

**Point de réévaluation.** — Si la console gagne des composants qui posent des classes
depuis un paquet tiers, le mécanisme s'applique tel quel : c'est le **périmètre de
scan** qu'il faut étendre, pas la liste des fichiers. À revoir si un jour un paquet
externe impose des utilitaires.
