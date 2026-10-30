# Registre des écarts — recette de bout en bout du 2026-10-29 (2ᵉ session)

**Objet** : consigner **tous** les écarts visuels et fonctionnels relevés par pilotage d'un vrai
navigateur (Chrome, DevTools) contre la pile de développement du Core, ainsi que les écarts
relevés par audit de code en lecture seule sur la même base.

**Pourquoi cette session** : le produit passe en ligne **une seule fois**. La recette du
2026-10-29 matin (`docs/specs/recette-navigateur.md`, 26 scénarios) s'est arrêtée au lot 13 ; les
lots 14, 15 et 17 ont été livrés **après** et n'ont jamais été pilotés dans un navigateur. Cette
session couvre ce manque, plus la console d'administration dans son ensemble.

**Périmètre** : console opérateur (`/admin`, 64 écrans GET), portail client (`/portal`), API
d'intégration (`/v1`), page de documentation (`/docs/api`).

**Méthode** (grille ECC `browser-qa`) : phase 1 smoke (console, réseau, en-têtes), phase 2
interactions (parcours réels, formulaires, erreurs), phase 3 visuel (375 / 500 / 768 / 1440 px,
débordements, captures), phase 4 accessibilité (audit WCAG 2.2 AA sur les 85 vues).

**Environnement** : pile Docker locale (`core-system`), PHP 8.4.27, Laravel 13.32.0, PostgreSQL 18,
Chrome 155. Base de développement, simulateur de paiement actif (`PAYMENT_SIMULATOR_ENABLED=true`).
`make ci` n'a **pas** été lancé pendant la session (il touche la base de développement et ferme les
sessions).

## Verdict

| | Bloquant | Majeur / élevé | Mineur / moyen / faible |
|---|---|---|---|
| Pilotage navigateur (console + portail) | **4** | 8 | 7 |
| Audit d'accessibilité (annexe A) | 9 | 74 | 36 |
| Audit de robustesse des formulaires (annexe B) | 6 | 21 | 8 |
| Audit de sécurité (annexe C) | 1 | 7 | 9 + 3 faibles |
| **Total** | **20** | **110** | **63** |

**Le produit n'est pas livrable en l'état.** Quatre bloquants fonctionnels findings de pilotage
navigateur : la console est inutilisable sur mobile (menu jamais ouvert), l'écran « Export des
données » du lot 15 renvoie une erreur 500 tant que sa migration n'est pas jouée, **aucune erreur de
validation n'est jamais affichée à l'opérateur** (20 formulaires dans 10 vues), et l'expression Alpine de
redimensionnement est du JavaScript invalide. Deux constats transversaux dominent le reste :
- **la console ne montre jamais une erreur** — le layout rend `session('status')` et `session('error')`
  mais **pas `$errors`** : le flash produit par `back()->withErrors()` est jeté. Le même défaut de
  famille (chaîne → `int` en mode strict) subsiste **sur toute l'API publique `/v1`** (15 commandes),
  alors qu'il a été corrigé côté console ce matin ;
- **la console n'a aucun en-tête de sécurité et charge Tailwind + Alpine depuis deux CDN
  publics**, alors que le portail client applique une CSP stricte et n'a aucune dépendance externe.

Ce que la recette **n'a pas** couvert et qu'il faudra faire avant la bascule : parcours de
facturation et d'encaissement de bout en bout, paiement réel par le portail, contrôle au clavier et
au lecteur d'écran en conditions réelles (l'audit d'accessibilité est une lecture de code), et
rejeu de la recette elle-même en CI (Playwright n'est pas installé : la recette est pilotée à la
main, donc **non rejouable**).

## Écarts fonctionnels

| # | Gravité | Scénario | Écart | Preuve | Correction proposée |
|---|---|---|---|---|---|
| **F-01** | **BLOQUANT** | S-05 — navigation sur téléphone | **Le menu de la console ne s'ouvre jamais en mobile.** Le bouton hamburger pilote un état local `open` (`x-data="{ open: false }"`, `@click="open = !open"`) alors que la barre latérale est affichée par `x-show="$store.navOpen || (window.innerWidth >= 768)"`. Les deux états ne sont pas reliés : le clic ne fait que remplacer l'icône (hamburger → croix). | `resources/views/layouts/admin.blade.php:47` (bouton) vs `:80` (barre latérale). Après rechargement à 500 px : `asideDisplay = none` avant **et après** le clic, `navOpen = false`, `open = true`. Capture `shots/06-mobile-menu-casse.png`. | Le bouton doit piloter `$store.navOpen` (`@click="$store.navOpen = !$store.navOpen"`), et non un état local. Ajouter `aria-expanded` + `aria-controls="sidebar-nav"`. |
| **F-02** | **BLOQUANT** | S-08 — redimensionnement de la fenêtre | **Expression Alpine invalide** : `@resize.window="window.innerWidth >= 768 && $store.navOpen = true"` — en JavaScript, `a && b = c` est une erreur de syntaxe (« Invalid left-hand side in assignment »). L'expression **n'est jamais exécutée** et lève une `SyntaxError` non rattrapée à chaque redimensionnement de fenêtre. | `resources/views/layouts/admin.blade.php:81`. Console : `Uncaught SyntaxError: Invalid left-hand side in assignment`. | Réécrire `@resize.window="if (window.innerWidth >= 768) $store.navOpen = true; else $store.navOpen = false"`. |
| **F-03** | **BLOQUANT** | S-06 — écran « Export des données » (lot 15) | **HTTP 500 — `relation "exports" does not exist`.** La migration `2026_10_29_100000_create_exports_table` est bien enregistrée (`loadMigrationsFrom`, `packages/export/src/ExportServiceProvider.php:54`) mais était **Pending** : elle n'avait jamais été jouée. L'écran du lot 15 n'a donc jamais été ouvert dans un navigateur. | `packages/export/src/Infrastructure/Persistence/Eloquent/EloquentExportRepository.php:41`. Après `artisan migrate` : écran fonctionnel, export demandé, ligne « En attente ». | Le code est correct ; le défaut est **de recette et de processus**. Corriger l'environnement (migration jouée) et **ajouter un garde-fou** : la recette navigateur doit commencer par un contrôle « aucune migration en attente », sinon l'écran reste muet. |
| F-04 | MAJEUR | S-04 — en-têtes HTTP de la console | **Aucun en-tête de sécurité** sur `/admin`, `/` et `/docs/api` : ni CSP, ni `X-Frame-Options`, ni `X-Content-Type-Options`, ni `Referrer-Policy`, ni HSTS. Le portail client, lui, applique une CSP stricte (`default-src 'none'`). L'asymétrie n'est pas documentée. | `curl -D-` sur les trois URL : seuls `Cache-Control` et `Date`. | Appliquer à la console le même socle que le portail : CSP stricte, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`. Voir F-05 (les CDN imposent de choisir). |
| F-05 | MAJEUR | S-01 — chargement de la console | **La console charge Tailwind 3.4.16 et Alpine 3.14.1 depuis deux CDN publics** (`cdn.tailwindcss.com`, `unpkg.com`). Dépendance réseau externe à l'exécution, build non reproductible, et Tailwind avertit explicitement que son CDN n'est pas destiné à la production. Le portail client, lui, est en CSS écrit à la main, sans build et sans CDN (ADR-0097) — les deux produits ne suivent pas la même règle. | `resources/views/layouts/admin.blade.php:15,18`. Console : `cdn.tailwindcss.com should not be used in production`. Réseau : 2 requêtes vers des tiers sur chaque page. | Vendoriser Tailwind + Alpine dans `public/` (build reproductible, CSP `script-src 'self'`) **ou** basculer la console sur le même modèle que le portail (CSS écrit, sans JS). Trancher par ADR : le portail est déjà conforme. |
| F-06 | MAJEUR | S-03 — barre latérale | **Deux entrées de menu pointent vers la même page générique** : « Wallets » → `/admin/projects` et « Facturation » → `/admin/projects`. Elles ne mènent nulle part de spécialisation. | `resources/views/layouts/admin.blade.php` (section ARGENT et FACTURATION) ; observé dans l'arbre d'accessibilité de toutes les pages. | Renvoyer « Wallets » vers la liste des portefeuilles (`/admin/projects/{id}/wallets` ou une vue portefeuille transverse) et « Facturation » vers la facturation du projet. Si l'écran transverse n'existe pas, **retirer l'entrée** plutôt que la faire pointer ailleurs. |
| F-07 | MAJEUR | S-03 — pied de la barre latérale | **Le rôle de l'opérateur est affiché en valeur technique brute** : `Rôle : platform_admin`. Lisible par un développeur, pas par un exploitant. | `resources/views/layouts/admin.blade.php` (pied de l'aside). | Libellé français par cas de `OperatorRole` (`Administrateur de plateforme`, `Administrateur d'organisation`, `Lecteur`, …). |
| F-08 | MAJEUR | S-02 — tableau de bord, S-06 — exports | **Identifiants internes bruts affichés aux opérateurs** : la colonne « PAR » des exports et la colonne « ACTEUR » du journal d'audit affichent `operator:01a1876f-73da-7381-b26e-df0494d9d983`, `service:e2e`, `api_key:01a1876f-…`. Les actions sont également brutes et non traduites : `operator.mfa_enrolled`, `api_key.issued`, `portal.session_opened`, `portal.settings_changed`. | Captures `shots/03-dashboard.png` et `shots/04-exports.png`. | Résoudre l'identifiant en libellé lisible (email de l'opérateur, nom du projet, référence courte de la ressource) et traduire les actions. Garder l'identifiant complet en infobulle ou dans un attribut `title`, pas en texte principal. |
| F-09 | MAJEUR | S-02 — page de connexion | **Le message d'erreur est rendu au milieu du formulaire**, entre le champ « Email » et le champ « Mot de passe », et sans `role="alert"` : il se lit comme s'il concernait le champ Email. Un lecteur d'écran ne l'annonce pas. | `resources/views/admin/auth/login.blade.php` — `<p class="mt-1.5 text-sm text-rose-600">` sans `role`, placé dans la grille entre les deux champs. | Bandeau d'erreur en tête de formulaire, `role="alert"`, focus sur le premier champ en erreur. |
| F-10 | MAJEUR | S-08 — passage desktop → mobile | **La barre latérale reste ouverte par-dessus le contenu en mobile** après un redimensionnement : `navOpen` reste `true`, transform à l'identité, aucun voile, le contenu est masqué sur 288 px. La cause est F-02 : le gestionnaire de redimensionnement est inopérant. | Après `resize` de 1440 → 500 px : `navOpen = true`, `asideRect.x = 0`, `mainRect.x = 0` (le contenu est dessous). Capture `shots/05-mobile-375.png`. | Corriger F-02 (fermer le tiroir quand on repasse sous 768 px) **et** ajouter un voile (`backdrop`) derrière le tiroir mobile ouvert. |
| **F-11** | **BLOQUANT** | S-09 — tout formulaire de la console | **Aucune erreur de validation n'est jamais affichée à l'opérateur.** Le layout `resources/views/layouts/admin.blade.php:310-325` rend `session('status')` et `session('error')` mais **pas `$errors`** : le flash produit par `back()->withErrors()` (`Concerns/HandlesBillingRefusals.php:42`) est **produit puis jeté**. 20 formulaires dans 10 vues sont concernés. **Vérifié dans le navigateur** : soumission d'un code de plan invalide (`MAJUSCULES`) → le serveur refuse, la page revient avec les champs pré-remplis, et **aucun message, aucune bordure rouge, aucun `role="alert"`** : le formulaire « a l'air de ne rien faire ». | Capture `shots/08-erreur-non-affichee.png` ; page `/admin/projects/{id}/plans/create`, code `MAJUSCULES` (règle serveur `^[a-z0-9][a-z0-9_-]*$`). Arbre d'accessibilité de la page revenue : aucun nœud d'erreur. | Ajouter au layout un bloc `@if ($errors->any())` avec `role="alert"`, et un composant `<x-admin.errors/>` réutilisé par les 10 vues. Voir annexe B, § « Points systémiques » n° 1. |
| **F-12** | **BLOQUANT** | S-09 — API publique `/v1` | **La famille « chaîne → `int` en mode strict » subsiste intacte sur l'API publique**, alors qu'elle a été corrigée côté console ce matin : 15 commandes typées (`price_minor`, `quantity`, `amount`, `trial_days`, `at_period_end`, `allow_self_cancel`…) sont alimentées par des `FormRequest` non castés. Une règle `integer` Laravel **valide** mais **ne caste pas** : `{"price_minor":"5000"}` passe la validation puis lève une `TypeError` → **500 au lieu de 422**, y compris sur `POST /v1/payments/paylink` (route d'argent, où la clé d'idempotence est consommée et un rejeu repart en 500). | Annexe B, défauts #11 à #21. Non rejoué dans le navigateur (API) — à confirmer par un test qui poste `{"price_minor":"5000"}` et attend 422. | Un cast unique au bon endroit : `prepareForValidation()` dans les `FormRequest` (modèle déjà présent : `packages/webhook/.../RotateWebhookSecretRequest.php:33-38`). Un test de non-régression poste une chaîne et attend 422 ferme toute la famille. |

## Écarts visuels

| # | Gravité | Scénario | Écart | Preuve | Correction proposée |
|---|---|---|---|---|---|
| V-01 | MAJEUR | S-06 — exports, mobile | **La colonne « PAR » écrase l'identifiant à un caractère par ligne** : `oper / ato / r:01 / a187 / 6f-7`. La colonne est trop étroite et le texte casse n'importe où. | Capture `shots/06-mobile-menu-casse.png`. | Le tableau doit être réellement défilable (`overflow-x-auto` sur le conteneur avec une largeur minimale par colonne) au lieu de laisser les colonnes s'écraser ; ne jamais afficher un UUID dans une colonne étroite — appliquer F-08. |
| V-02 | MINEUR | S-03 — barre latérale | **« Corrections d'exploitation » est tronqué en « Corrections d'exploi… »**, sans indication de la suite ni infobulle. | Capture `shots/03-dashboard.png`. | `whitespace-nowrap` + largeur de la barre latérale adaptée, ou libellé raccourci (« Corrections »). |
| V-03 | MINEUR | S-02 — page de connexion | **Le logo est un glyphe de lignes horizontales** dans un carré indigo : il se lit comme un bouton hamburger sur une page où il n'y a aucun menu. L'utilisateur va essayer de cliquer. | `resources/views/admin/auth/login.blade.php` — SVG `M3 10h18M3 6h18M3 14h18M3 18h18`. Capture `shots/01-login-1440.png`. | Un emblème distinct (monogramme, ou glyphe de couches) et `aria-hidden="true"`. |
| V-04 | MINEUR | S-02 — page MFA | **La grille à deux colonnes (QR à gauche, secret à droite) est à l'étroit** dans une carte de 448 px : la colonne de droite fait ~200 px, le texte explicatif casse sur 7 lignes. | Capture `shots/02-mfa-enroll.png`. | Empiler les deux colonnes sous 640 px ou élargir la carte sur cette page. |
| V-05 | MINEUR | S-06 — exports | **La phrase d'information finale** (« Le lien de téléchargement est généré au clic et valable 15 minutes. ») est hors de toute section, sans titre, et se lit comme une ligne résiduelle. | Capture `shots/04-exports.png`. | La rattacher au tableau (« Exports précédents ») ou à la section « Demander un export ». |
| V-06 | MINEUR | S-09 — formulaire « Nouveau plan » | **Le texte d'aide du champ « Code » se casse mal** : « Stable dans le temps : minuscules, chiffres, - et _ . » — le tiret et l'underscore sont posés comme éléments en ligne distincts, et le point final **tombe seul sur sa ligne**. | Capture `shots/08-erreur-non-affichee.png`. | Composer la phrase en un seul texte (« minuscules, chiffres, tirets et underscores »), ou garder les signes mais empêcher la coupure. |
| V-07 | MINEUR | S-10 — portail client | **Pluriel fautif** : « 1 facture(s) en attente ». | Capture `shots/07-portail-accueil.png`. | Accord automatique : « 1 facture en attente » / « 2 factures en attente ». |
| V-08 | MINEUR | S-09 — formulaire « Nouveau plan » | **Incohérence de couleur des boutons primaires** : « Créer le plan » est noir (`bg-slate-900`) alors que le reste de la console utilise l'indigo de la barre latérale. | Capture `shots/08-erreur-non-affichee.png`. | Aligner sur la couleur d'action du design system. |

## Scénarios couverts par cette session

| # | Scénario | Résultat |
|---|---|---|
| S-01 | Chargement de la console : console, réseau, en-têtes, ressources tierces | ✔ exécuté — 2 CDN, 0 en-tête de sécurité (F-04, F-05) |
| S-02 | Connexion opérateur : mot de passe, enrôlement MFA (TOTP), tableau de bord | ✔ exécuté — enrôlement validé, 2 écarts (F-09, V-03) |
| S-03 | Balayage de la barre latérale (13 entrées) et des 64 écrans GET de la console | ✔ exécuté — 1 500 après correction de F-03, 0 autre 500 |
| S-04 | En-têtes HTTP de la console, de la racine et de la documentation | ✔ exécuté — F-04 |
| S-05 | Console au format mobile (375 / 500 px) : navigation, débordements, tableaux | ✔ exécuté — F-01, F-10, V-01 |
| S-06 | Export des données : demande réelle, ligne « En attente », colonne « PAR » | ✔ exécuté après F-03 — 1 écart (F-08, V-01) |
| S-07 | Journal d'audit et identifiants affichés | ✔ exécuté — F-08 |
| S-08 | Redimensionnement de fenêtre (desktop ↔ mobile) | ✔ exécuté — F-02, F-10 |
| S-09 | Formulaire « Nouveau plan » : soumission d'un code invalide, retour arrière, rendu de l'erreur | ✔ exécuté — **F-11 vérifié**, V-06, V-08 |
| S-10 | Portail client : entrée par lien à usage unique, accueil, facture à régler, quotas, abonnement | ✔ exécuté — **le portail est conforme** ; 1 écart de copie (V-07) |

Scénarios **non couverts** par cette session, à instruire : parcours de facturation et d'encaissement,
parcours portail client complet (paiement, quotas, connexion par code), reprise après incident
(export, rapprochement), contrôle du clavier et du lecteur d'écran en conditions réelles
(l'audit d'accessibilité est une lecture de code, pas un test au lecteur d'écran).

## Annexes

Ces trois annexes ont été produites par des audits ECC **en lecture seule** sur le même dépôt, le
même jour, avec la même grille. Elles contiennent le détail exhaustif, les « points systémiques »
et — aussi important — la liste de **ce qui est déjà couvert**, à ne pas casser.

- **Annexe A** — accessibilité WCAG 2.2 AA des 85 vues : `annexe-accessibilite-2026-10-29.md`
  (119 écarts — 9 bloquants, 74 majeurs, 36 mineurs). Console : 104 écarts ; portail : 7 écarts
  mineurs. Plan de correction en 12 lots ; les ordres 1 à 4 soldent 63 écarts.
- **Annexe B** — robustesse des formulaires et de la console : `annexe-robustesse-2026-10-29.md`
  (35 défauts — 6 bloquants, 21 majeurs, 8 mineurs). Le point saillant : le même défaut de famille
  que le 2026-10-29 (chaîne → `int`) a été curé **côté console** mais subsiste **côté API**.
- **Annexe C** — sécurité : `annexe-securite-2026-10-29.md` (20 écarts — 1 bloquant, 7 élevés,
  9 moyens, 3 faibles) + 12 garde-fous manquants (G-1 à G-12) à écrire.

### Ce qui est solide et à ne pas casser

Les audits convergent : le périmètre **API + portail** est nettement mieux tenu que la console web.
Sont vérifiés et corrects : le manifeste IDOR généré (G15, exhaustif par construction) sur la
console, la règle `AccessScope` obligatoire (G16, structurelle via PHPStan), l'isolation financière
inter-projets par trigger (G13), l'idempotence HTTP sur les mutations d'argent (G7), l'outbox
transactionnelle (G8), l'isolation inter-modules (G3c), la sortie HTTP unique et l'anti-SSRF
complets (G6c, y compris le re-pinning DNS), le lien à usage unique du portail (claim atomique),
l'anti-rejeu TOTP, le chiffrement des PII avec index aveugle, l'export chiffré par blocs avec
`403` si altéré, la validation stricte des `return_url` du portail, le « dernier propriétaire » et
les « quatre yeux ». La recette du 2026-10-29 a de plus laissé **38 vues sans aucun écart**
d'accessibilité — dont tout le portail.

## Plan de correction proposé

1. **F-01, F-02, F-10, F-09** — un seul fichier (`resources/views/layouts/admin.blade.php`), un seul
   passage : relier le bouton à `$store.navOpen`, réécrire l'expression de redimensionnement,
   ajouter `aria-expanded` / `aria-controls`, un voile, et **rendre `$errors`**. Ces écarts sont
   tous la même erreur d'architecture : **deux états pour une seule chose**, et un état produit
   puis jeté.
2. **F-11** — le bloc d'erreurs global : un composant `<x-admin.errors/>` + dix vues. C'est le
   correctif au plus fort rendement de toute la liste (une seule cause, 20 formulaires).
3. **F-12** — un cast unique dans les `FormRequest` (`prepareForValidation()`), plus un test
   « chaîne → 422 » qui ferme la famille entière.
4. **F-03** — jouer la migration, puis ajouter à la recette un contrôle « aucune migration en
   attente » avant le démarrage.
5. **F-04, F-05** — une décision d'architecture (ADR) : CSP et ressources sur la console. Le
   modèle du portail (CSS écrit, CSP stricte, zéro CDN) est déjà dans le dépôt ; le plus simple est
   de l'y aligner.
6. **F-06, F-07, F-08** — lisibilité de la console : liens de menu corrects, libellés de rôle,
   identifiants résolus en noms.
7. **V-01 → V-08** — revue visuelle, avec le portail comme gabarit (il est conforme).
8. **Annexe A** — plan de correction en 12 lots déjà chiffré : les ordres 1 à 4 soldent 63 écarts
   (9 bloquants + 54 majeurs) pour environ 24 fichiers.
9. **Annexe C** — écrire les 12 garde-fous manquants (G-1 à G-12) comme des *fitness functions*, pas
   comme des correctifs ponctuels : c'est ce qui empêchera ces familles de revenir.

## État après correction (2026-10-30)

Les 193 écarts consignés ci-dessus ont été traités sur la branche
`fix/ecarts-recette-2026-10-29`. La suite passe (1 875 tests), PHPStan niveau 10 est
vert, et les 23 garde-fous actifs ont été prouvés mordants (`make guard-test`).

**Soldés — les 4 bloquants et les 8 majeurs du pilotage navigateur** : F-01 (tiroir mobile
inert), F-02 (expression Alpine invalide), F-03 (écran d'export en 500), F-04 (aucun en-tête
de sécurité), F-05 (CDN publics), F-06 (liens de menu), F-07 (rôle en valeur technique),
F-08 (identifiants bruts), F-09 (erreur de connexion mal placée), F-10 (voile du tiroir),
F-11 (aucune erreur de validation affichée), F-12 (chaîne → `int` sur l'API).

**Soldés en sécurité** : en-têtes et CSP de la console (#1, `'unsafe-inline'` retiré de
`script-src`), expurgation du TOTP et du MAC du prestataire (#2, #3), aucun secret dans le
store de session (#4), élévation par second facteur sur les actions sensibles (#5),
symétrie du dernier administrateur avec verrou de ligne (#6), écritures par cookie refusées
(#7), jeton de sidecar obligatoire en production (#8), PII non journalisée (#9),
`allowed_asset_origins` sur le portail (#10), `Str::isUuid` et limitation de débit (#11),
refus du contournement MFA hors local (#12), borne de taille et de profondeur des corps (#13),
documentation épinglée et réservée en production (#14), défi MFA lié à la session (#15),
lien d'export lié à l'opérateur et à usage unique (#16), plafond de débit de la console (#17),
schéma `https` obligatoire sur `return_url` (#18), expurgation du nom du client (#19),
manifeste IDOR du portail (#20).

**Soldés en garde-fous** : G-1 (en-têtes par plan), G-3 (aucun secret en session),
G-4 (élévation de privilège), G-5 (dernier administrateur **et** dernier `org_finance`),
G-6 (CSRF sur le plan authentifié par cookie), G-7 (drapeaux refusés en production),
G-8 (bornes d'entrée), G-9 (résolution par le serveur), G-10 (manifeste IDOR étendu),
G-11 (le lien signé est un secret), G-12 (plafond webhook par projet).

**Restent ouverts, et pourquoi** :

| # | Écart | Nature |
|---|---|---|
| #18 | `return_url` accepte encore n'importe quel hôte `https://` | **Résiduel assumé et écrit** (ADR-0103) : `return_url` n'est atteignable que par une clé API du projet — l'adversaire a déjà l'accès métier, et le projet peut déjà rediriger son client où il veut. Le risque technique réel (schémas `javascript://`, `data:`) est fermé par `starts_with:https://`. L'allowlist par projet reste au portail, où la page de connexion est publique ; elle redevient obligatoire si le Core expose un paiement accessible à un utilisateur final. |
| G-2 | La fitness function de symétrie entre en-têtes et clés JSON n'est pas écrite | Les listes sont complétées ; l'automatisation reste à faire. |
| G-1 (résiduel) | `'unsafe-eval'` reste dans `script-src` | Le build standard d'Alpine évalue les expressions avec `new Function`. Le lot est décrit par ADR-0102 ; son intégration suppose l'approbation de la source externe du build CSP. |

## Cartouche

| | |
|---|---|
| Date | 2026-10-29, session de recette du soir |
| Périmètre | console opérateur (64 écrans), portail client, API `/v1`, documentation |
| Méthode | pilotage Chrome (DevTools) + 3 audits ECC en lecture seule (accessibilité, robustesse, sécurité) |
| Écarts consignés | 4 bloquants / 8 majeurs / 7 mineurs (navigateur) + 119 (annexe A) + 35 (annexe B) + 20 (annexe C) |
| Environnement | pile Docker locale, PHP 8.4.27, Laravel 13.32.0, PostgreSQL 18, Chrome 155 |
| Hors périmètre | modules `fees`, `storage`, `developer-platform` (ADR-0099), `payout` (lot 19, non livré) |
