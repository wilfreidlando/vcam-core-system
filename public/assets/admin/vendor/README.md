# Bibliothèques de la console — auto-hébergées

La console opérateur chargeait Tailwind et Alpine depuis deux CDN publics
(`cdn.tailwindcss.com`, `unpkg.com`) : une compromission de l'un d'eux exécutait du
JavaScript arbitraire sur l'origine de la console, avec son jeton CSRF et ses secrets
affichés une fois (recette du 2026-10-29, F-05 ; annexe sécurité n° 1). Les deux fichiers
sont désormais servis par l'application, aux versions qui étaient épinglées.

| Fichier | Version | Origine | SHA-256 |
| ------- | ------- | ------- | ------- |
| `tailwindcss-3.4.16.js` | 3.4.16 (Play CDN, compilation à la volée) | `https://cdn.tailwindcss.com/3.4.16` | `3f81aa7f6ecdb1acc14c202e513dfee00b6c7703cd81ce1be25bf5215a92e8cb` |
| `alpine-csp-3.14.1.min.js` | 3.14.1 (build CSP, paquet `@alpinejs/csp`) | `https://unpkg.com/@alpinejs/csp@3.14.1/dist/cdn.min.js` | `40f00d7aaa543eabd17c3a0f79ce2ac62e6be17caa9564527c20ec39152bb157` |

Les deux sont sous licence MIT. `tests/Feature/Admin/AdminVendoredAssetsTest.php` vérifie
que les fichiers existent, que leur empreinte n'a pas changé et que le layout ne référence
plus aucun hôte externe.

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

## Mettre à jour une version

1. Télécharger le nouveau fichier depuis l'origine ci-dessus, **à une version précise**
   (jamais `latest`).
2. Remplacer le fichier, renommer avec la version, mettre à jour ce tableau (version,
   empreinte `sha256sum`) et les deux `<script>` du layout (`resources/views/layouts/admin.blade.php`).
3. Mettre à jour les empreintes attendues dans le test.

## Pourquoi pas un vrai build

Le Play CDN de Tailwind compile le CSS dans le navigateur : il fonctionne sans bundler mais
n'est pas destiné à la production (Tailwind l'écrit dans la console du navigateur). Passer à
un CSS compilé (`tailwindcss` en CLI, sortie dans `public/assets/admin/`) supprimerait aussi
le flash de contenu sans style ; ce serait un changement d'outillage (Node dans l'image de
build) à décider par ADR. Alpine, lui, exige `'unsafe-eval'` dans sa version standard ;
c'est pourquoi la console utilise son build « CSP » (`@alpinejs/csp`), qui lit les
expressions des gabarits comme des chemins de propriétés et n'appelle jamais
`new Function` (ADR-0104). Le build standard a été retiré de `public/` : il ne doit pas
rester téléchargeable, pour que personne ne puisse le remettre à la place du build CSP.
