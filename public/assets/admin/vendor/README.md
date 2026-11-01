# Bibliothèque et feuille de style de la console — auto-hébergées

La console opérateur chargeait Tailwind et Alpine depuis deux CDN publics
(`cdn.tailwindcss.com`, `unpkg.com`) : une compromission de l'un d'eux exécutait du
JavaScript arbitraire sur l'origine de la console, avec son jeton CSRF et ses secrets
affichés une fois (recette du 2026-10-29, F-05 ; annexe sécurité n° 1). Les deux sont
désormais servis par l'application.

| Fichier | Version | Origine | SHA-256 |
| ------- | ------- | ------- | ------- |
| `alpine-csp-3.14.1.min.js` | 3.14.1 (build CSP, paquet `@alpinejs/csp`) | `https://unpkg.com/@alpinejs/csp@3.14.1/dist/cdn.min.js` | `40f00d7aaa543eabd17c3a0f79ce2ac62e6be17caa9564527c20ec39152bb157` |

Alpine est sous licence MIT. `tests/Feature/Admin/AdminVendoredAssetsTest.php` vérifie
que le fichier existe, que son empreinte n'a pas changé et que le layout ne référence
plus aucun hôte externe.

## Tailwind : une feuille compilée, pas une bibliothèque

Tailwind n'est plus un fichier servi : c'est une **feuille compilée**,
`public/assets/admin/console.css` (30 859 octets, 6 Ko gzip), produite par la CLI
Tailwind 3.4.16 à partir des gabarits de la console.

[ADR-0106](../../../../docs/adr/0106-feuille-de-style-de-la-console-compilee.md)
explique pourquoi. Le « Play CDN » compilait le CSS **dans le navigateur**, à chaque
page, à partir du DOM vivant : 440 Ko de script (134 Ko gzip) pour un résultat de
30 Ko, un flash de contenu sans style à chaque navigation, et une console qui
attendait le script avant de peindre.

Le fichier compilé est **versionné avec le code** : les images Docker n'ont pas besoin
de Node, et la porte CI « styles » le recompilera pour prouver qu'il est à jour.

| | Play CDN (avant) | Fichier compilé (maintenant) |
| --- | --- | --- |
| Poids servi | 440 Ko (134 Ko gzip) | 30 Ko (6 Ko gzip) |
| Compilation | dans le navigateur, à chaque page | une fois, par le fichier |
| Flash de contenu sans style | oui | non |
| Node requis | à l'exécution | à la compilation seulement |

La version de Tailwind est inchangée (3.4.16) : le jeu de classes est identique, seul
celui qui compile change.

### Le périmètre de compilation

La compilation statique ne lit que ses sources, là où le Play CDN lisait le DOM
vivant. Une classe posée par le JavaScript — `rotate-90` sur le chevron des détails —
n'existe dans aucun gabarit : elle aurait été **perdue en silence**. Le périmètre
(`tailwind.config.js`) include donc les gabarits, le code applicatif (`app/`,
`packages/`) et le fichier des composants Alpine (`console.js`). Coût : +823 octets.

### Compiler

```bash
npm ci          # une fois
npm run build:css
```

ou, sans Node sur la machine :

```bash
make css
```

### Vérifier que la feuille est à jour

```bash
npm run check:css   # recompile et compare au fichier versionné
make css-check
```

La porte CI « styles » fait la même chose : elle échoue si une vue a reçu une classe
sans que la feuille soit recompilée.

## Le fichier voisin `../console.js`

`public/assets/admin/console.js` n'est **pas** une bibliothèque : c'est le code de
l'application (les composants Alpine de la console), qui vivait dans un `<script>` inline.
Il est servi comme un fichier pour que la CSP de la console n'ait plus besoin de
`'unsafe-inline'` dans `script-src` (ADR-0102). Il est chargé **avant** Alpine : les
composants sont recherchés dès le premier rendu du tiroir, et `alpine:init` se déclenche
au démarrage d'Alpine.

Le build CSP d'Alpine n'évalue pas une expression : il lit un **chemin de propriétés**
(`navOpen`, `label`, `all`). Une expression qui contient une affectation
(`@click="open = ! open"`), un littéral (`x-data="{ open: false }"`), un ternaire
(`:aria-expanded="open ? 'true' : 'false'"`) ou un appel (`@click="show()"`) n'est pas
signalée comme une erreur : l'évaluateur se contente d'avertir dans la console du
navigateur, et le comportement disparaît. L'état de la console vit donc dans les
composants `Alpine.data()` de ce fichier, et
`tests/Feature/Admin/ConsoleCspExpressionsTest.php` échoue si une vue réintroduit une
expression que ce build ne sait pas lire.

## Mettre à jour une version d'Alpine

1. Télécharger le nouveau fichier depuis l'origine ci-dessus, **à une version précise**
   (jamais `latest`).
2. Remplacer le fichier, renommer avec la version, mettre à jour ce tableau (version,
   empreinte `sha256sum`) et le `<script>` du layout
   (`resources/views/layouts/admin.blade.php`).
3. Mettre à jour les empreintes attendues dans le test.
