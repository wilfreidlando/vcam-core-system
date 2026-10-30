# ADR-0102 — CSP de la console opérateur : ce qui reste de `unsafe-eval`, et ce qui le fera disparaître

- **Statut** : Proposé
- **Date** : 2026-10-30
- **Décideurs** : comité d'architecture (dérives D1 périmètre · D5 contrat)
- **Dérives concernées** : D1 périmètre · D5 contrat

## Contexte

La recette navigateur du 2026-10-29 (registre `docs/audit/ecarts-recette-2026-10-29.md`, F-04 et F-05 ; annexe sécurité n° 1 et garde-fou G-1) a établi deux faits :

1. la console opérateur ne servait **aucun** en-tête de sécurité — ni CSP, ni `X-Frame-Options`, ni `nosniff` ;
2. elle chargeait Tailwind et Alpine depuis deux **CDN publics** sans `integrity`, sur la même origine que des jetons CSRF et des secrets affichés une fois.

Le portail client, lui, applique déjà une CSP stricte (`default-src 'none'`, zéro JavaScript, zéro CDN) et n'a que sept écarts d'accessibilité mineurs : c'est le gabarit, pas la console.

Le correctif livré le 2026-10-29 (commit `26eebf0`) a donc : ajouté un middleware `AdminSecurityHeaders` sur tout le groupe `/admin`, auto-hébergé Tailwind 3.4.16 et Alpine 3.14.1 dans `public/assets/admin/vendor/` (empreintes SHA-256 contrôlées par `AdminVendoredAssetsTest`), et posé `script-src 'self'`.

Il reste deux marqueurs de `unsafe-` dans la CSP en vigueur :

```
script-src 'self' 'unsafe-inline' 'unsafe-eval'
```

Leur cause est technique et non un choix de confort :

- **`'unsafe-eval'`** : le build *standard* d'Alpine évalue les expressions des gabarits (`x-data`, `@click`, `:class`) avec `new Function`. Le build *CSP* d'Alpine supprime cet usage, mais **interdit les expressions en ligne** : `@click="$store.nav.toggle()"` et `:class="{ 'is-open': $store.nav.open }"` cesseraient de fonctionner. La console compte aujourd'hui 21 occurrences d'expressions Alpine réparties sur 15 vues.
- **`'unsafe-inline'`** : le layout porte un script inline (la définition du store `nav`) et plusieurs vues portent des gestionnaires en attribut (`onchange` sur le sélecteur de rôle d'un membre d'organisation). Le nonce par script inline supprime le premier, pas le second.

Le point important : **la suppression de `unsafe-eval` n'est pas un réglage**, c'est un travail de fond des vues. Le poser comme simple case à cocher ferait échouer la console en silence — le symptôme exact du F-01 (un état d'Alpine mort) déjà payé une fois.

## Décision

**Décision 1 — `unsafe-eval` reste, et le retrait est un chantier daté, pas une intention.**

La CSP de la console reste `script-src 'self' 'unsafe-inline' 'unsafe-eval'` tant que le build CSP d'Alpine n'est pas en place. Le middleware `AdminSecurityHeaders` conserve la justification en tête de classe (elle est lue à chaque modification de la CSP).

**Décision 2 — le build CSP d'Alpine est retenu comme la voie de sortie, et la bascule est un lot à part entière.**

Le lot porte : (a) la migration de chaque expression Alpine vers le build CSP (les expressions évaluables statiquement deviennent des attributs, les autres deviennent des méthodes enregistrées via `Alpine.data()`) ; (b) le déplacement du script inline du layout vers un fichier servi par l'application, ce qui permet de retirer `'unsafe-inline'` ; (c) le remplacement des gestionnaires `onchange` en attribut par des écouteurs délégués ; (d) la mise à jour de la CSP.

**Décision 3 — le déclencheur du lot est explicite, et sa date est celle de la mise en production.**

Le lot « CSP stricte de la console » est livré **avec** la mise en production, pas après. Le produit passe en ligne une fois, et une CSP qui autorise l'exécution de JavaScript arbitraire sur l'origine de la console n'est pas un état de production acceptable pour un produit qui affiche des clés API en clair.

Le lot est **faisable** : le build CSP d'Alpine (`@alpinejs/csp`, version 3.14.1, la même que celle du build standard déjà vendorisé) évalue les expressions des gabarits par **substitution de jetons** — il ne fait pas appel à `new Function` et se replie sur un évaluateur restreint quand le navigateur refuse le constructeur de fonctions. Les ~60 expressions de la console (13 `x-data`, 11 `x-show`, 7 `@click`, 5 `x-model`, 16 `:class`, 6 `:aria-expanded`, 3 `x-ref`, la capture de focus des modales) se réécrivent en méthodes déclarées par `Alpine.data()` ; le script inline du layout (le store `nav`) devient un fichier servi par l'application.

**Décision 3 bis — l'intégration du build CSP est soumise à approbation, et le lot ne peut pas la contourner.**

Le build CSP est un paquet tiers downloaded d'`unpkg.com`. Son intégration dans `public/assets/admin/vendor/` — comme celle de Tailwind et d'Alpine, qui y sont déjà, version épinglée et empreinte SHA-256 contrôlée par `AdminVendoredAssetsTest` — suppose que la **source externe soit nommée et approuvée**. Cette approbation fait partie du lot : tant qu'elle n'est pas donnée, le lot n'est pas livrable, et la CSP garde ses deux marqueurs.

Ce qui est **déjà** acquis sur ce chemin, et qui n'attend pas cette approbation :
- le script inline du layout est déplaçable dans un fichier servi par l'application — `'unsafe-inline'` n'est donc plus nécessaire à `script-src` pour cette raison ;
- aucun gestionnaire `on*=` en attribut ne subsiste dans les vues de la console ;
- les seules interpolations de valeurs dans des directives Alpine sont deux booléens calculés côté serveur (`webhook-endpoints/create.blade.php`), donc non injectables.

Autrement dit : même si l'approbation tardait, **`'unsafe-inline'` peut être retiré dès maintenant** — c'est `'unsafe-eval'` qui dépend du build CSP.

**Décision 4 — le garde-fou qui accompagne la décision.**

Tant que `unsafe-eval` est présent, `AdminSecurityHeadersTest` vérifie que la CSP contient `script-src 'self'` et **qu'aucun hôte tiers n'y est autorisé** (`script-src` ne contient aucun `http://` ni `https://`). C'est ce qui empêche le retour d'un CDN — le risque qui a motivé le correctif. Le test échoue si une ressource tierce est réintroduite.

**Décision 5 — Tailwind reste en compilation à l'exécution, et c'est acté.**

Le fichier `tailwindcss-3.4.16.js` auto-hébergé est le **Play CDN** : il compile le CSS dans le navigateur. Tailwind écrit lui-même que ce build n'est pas destiné à la production. Compiler le CSS (`tailwindcss` en CLI, sortie dans `public/assets/admin/`) supprimerait ce build au prix d'un outillage Node dans l'image de build — décision d'outillage, hors périmètre de ce correctif de sécurité. Elle est renvoyée à un ADR d'outillage distinct, qui devra aussi traiter l'absence de `unsafe-eval` pour le CSS compilé.

## Alternatives écartées

**Basculer la console sur le modèle du portail (CSS écrit, zéro JavaScript).** — Rejeté : c'est la voie la plus stricte et la plus conforme, mais elle impose de réécrire les 15 vues qui utilisent Alpine (les 7 modales de confirmation, le tiroir mobile, les bascules) en HTML pur. Le coût est disproportionné par rapport au gain : la CSP `script-src 'self'` interdit déjà tout hôte tiers, et `'unsafe-eval'` n'est exploitable qu'en présence d'une injection de contenu. La réduction du risque passe par le build CSP d'Alpine, qui atteint le même but pour un dixième du travail.

**Ajouter `integrity` + `crossorigin` aux CDN au lieu de les auto-héberger.** — Rejeté : `integrity` protège le *fichier* contre la modification, pas le *service* contre la compromission de ses comptes ; et le réseau sortant de la console reste une dépendance. L'auto-hébergement (déjà livré) supprime les deux.

**Retirer `unsafe-eval` tout de suite en activant le build CSP d'Alpine.** — Rejeté : le build CSP n'interdit pas les expressions, il les refuse. Activer l'option sans migrer les vues casserait le tiroir mobile, les sept modales et les bascules — le défaut F-01 reproduit à l'identique, mais cette fois par la sécurité. C'est la raison pour laquelle ce lot est un lot, et pas un interrupteur.

**Supprimer les expressions Alpine au profit de `Alpine.data()` partout, sans le build CSP.** — Rejeté : cela ne retire pas `unsafe-eval` (le build standard l'exige de toute façon) et ne change rien à la surface d'attaque.

## Conséquences

**Ce qui devient plus facile** : la console est alignée sur le portail du point de vue de la surface d'attaque — un seul hôte de script possible (`'self'`), un contrôle CI qui empêche le retour d'un CDN.

**Ce qui devient plus difficile** : le lot « CSP stricte de la console » est un travail de fond sur 15 vues, avec un risque de régression fonctionnelle réel (F-01 a déjà coûté une session). Il exige une recette navigateur du tiroir mobile et des sept modales — la recette pilotée à la main, pas rejouable en CI aujourd'hui.

**Ce qui est engagé** :
- `AdminSecurityHeaders::CSP` reste `script-src 'self' 'unsafe-inline' 'unsafe-eval'` jusqu'à la livraison du lot CSP stricte.
- `AdminSecurityHeadersTest` verrouille l'absence de tout hôte tiers dans `script-src` — c'est le garde-fou qui tient en attendant.
- Le passage en production est **conditionné** à la livraison du lot CSP stricte de la console. Ce n'est pas une amélioration différée : c'est un préalable.

**Points de réévaluation** : à la livraison du lot CSP stricte, cet ADR est remplacé par un ADR écrit (la CSP finale et le build retenu), et la CSP en vigueur passe à `script-src 'self'`.
