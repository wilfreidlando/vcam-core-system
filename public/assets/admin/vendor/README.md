# Bibliothèques de la console — auto-hébergées

La console opérateur chargeait Tailwind et Alpine depuis deux CDN publics
(`cdn.tailwindcss.com`, `unpkg.com`) : une compromission de l'un d'eux exécutait du
JavaScript arbitraire sur l'origine de la console, avec son jeton CSRF et ses secrets
affichés une fois (recette du 2026-10-29, F-05 ; annexe sécurité n° 1). Les deux fichiers
sont désormais servis par l'application, aux versions qui étaient épinglées.

| Fichier | Version | Origine | SHA-256 |
| ------- | ------- | ------- | ------- |
| `tailwindcss-3.4.16.js` | 3.4.16 (Play CDN, compilation à la volée) | `https://cdn.tailwindcss.com/3.4.16` | `3f81aa7f6ecdb1acc14c202e513dfee00b6c7703cd81ce1be25bf5215a92e8cb` |
| `alpine-3.14.1.min.js` | 3.14.1 (build standard) | `https://unpkg.com/alpinejs@3.14.1/dist/cdn.min.js` | `358d9afbb1ab5befa2f48061a30776e5bcd7707f410a606ba985f98bc3b1c034` |

Les deux sont sous licence MIT. `tests/Feature/Admin/AdminVendoredAssetsTest.php` vérifie
que les fichiers existent, que leur empreinte n'a pas changé et que le layout ne référence
plus aucun hôte externe.

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
build) à décider par ADR. Alpine, lui, exige `'unsafe-eval'` dans sa version standard ; sa
version « CSP » le supprimerait mais interdit les expressions inline des gabarits.
