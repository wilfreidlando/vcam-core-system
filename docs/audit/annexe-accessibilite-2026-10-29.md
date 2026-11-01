# Annexe A — Audit d'accessibilité WCAG 2.2 AA (2026-10-29)

**Méthode** — lecture intégrale des 85 vues Blade du dépôt, comparaison au design system de la console, calculs de contraste WCAG (luminance relative) sur les tokens réellement utilisés, revue de `public/assets/portal/portal.css` et de `packages/portal/src/Application/Support/PortalPalette.php`. Audit en lecture seule : aucun fichier modifié, aucune commande base.

**Périmètre** — `resources/views/**` (66 vues, dont 3 overrides vendor Scramble), `packages/portal/resources/views/**` (19 vues). `packages/admin/resources/views/**` **n'existe pas** : le design system de la console est en réalité `resources/views/layouts/admin.blade.php` + `resources/views/components/admin/*` (5 composants : `table`, `search-box`, `pagination`, `stat`, `status-badge`). `packages/payment/resources/views/simulator-checkout.blade.php` est hors périmètre produit (staging) — traité en fin de registre.

**Verdict** — **119 écarts : 9 bloquants, 74 majeurs, 36 mineurs.** La console d'administration concentre 104 écarts (9 bloquants, 74 majeurs, 21 mineurs) sur 63 vues ; le portail client est conforme (7 écarts mineurs sur 19 vues).

Les chemins sont relatifs à la racine du dépôt. Le format `chemin:ligne` désigne le fichier et la ligne.

---

## 1. Registre d'écarts complet

### A — Structure, landmarks, document (14 écarts)

| # | Fichier:ligne | Critère WCAG (niveau) | Gravité | Description | Correction proposée |
|---|---|---|---|---|---|
| A1 | `resources/views/layouts/admin.blade.php:101` | 1.3.1 / 4.1.2 (A) | majeur | `<nav>` principal **sans nom accessible** — 2 `nav` dans la page, un seul nommé (« Fil d'Ariane ») | `aria-label="Navigation principale"` sur le `<nav>` de la barre latérale |
| A2 | `resources/views/layouts/admin.blade.php:78-83` | 1.3.1 / 2.4.1 (A) | mineur | Le `<nav>` est imbriqué dans l'`<aside>` (landmark « complementary ») : la navigation principale n'est pas un landmark de premier niveau | Sortir le `<nav>` hors de l'`<aside>`, ou nommer l'aside (`aria-label="Menu latéral"`) |
| A3 | `resources/views/layouts/admin.blade.php:46-57` | 1.3.1 (A) | mineur | `<header>` en `md:hidden` → **aucun landmark `banner` sur desktop** ; aucun `<footer>` (`contentinfo`) dans toute la console | `<header>` visible ≥ md, `<footer>` dans l'aside (déconnexion) ou dans le `<main>` |
| A4 | `resources/views/layouts/admin.blade.php:50-56` | 4.1.2 (AA) | majeur | Bouton hamburger : `aria-label="Afficher/masquer le menu"` statique, **ni `aria-expanded` ni `aria-controls`** | `:aria-expanded="open"` + `aria-controls="sidebar-nav"` + `id` sur l'aside |
| A5 | `resources/views/layouts/admin.blade.php:302` | 1.3.1 / 1.4.10 (A/AA) | majeur | `@yield('subtitle')` dans `hidden sm:block` → le nom du projet est **absent sous 640 px** (invisible ET retiré de l'arbre d'accessibilité) | Rendre le sous-titre visible en mobile (ou le dupliquer dans le `<h1>`) |
| A6 | `resources/views/layouts/admin.blade.php:1-38` | 2.4.1 (A) | majeur | **Aucun lien d'évitement** (« Aller au contenu ») ; la console a 15 liens de sidebar répétés + fil d'Ariane sur chaque page | `<a href="#main" class="sr-only focus:not-sr-only">Aller au contenu</a>` + `id="main"` sur le `<main>` |
| A7 | `resources/views/layouts/admin.blade.php:32-36` | **2.4.11 (AA)** + 2.4.7 (A) | **bloquant** | `outline: none` + `box-shadow: 0 0 0 3px rgb(99 102 241 / 0.25)` : halo indigo 25 % ≈ **1.39:1** sur blanc. SC 2.4.11 (nouveau en 2.2) exige ≥ 3:1 — touche **tous** les champs de la console | `outline: 2px solid #4338ca; outline-offset: 2px` (ou `box-shadow` indigo 600 opaque, ≥ 3:1) |
| A8 | `resources/views/layouts/admin.blade.php:30, 83` | 2.3.3 (AAA) / BP | mineur | `.flash { animation: flash-in 180ms }` + `transition-transform duration-150` : **`prefers-reduced-motion` non respecté** (le portail le fait, la console non) | Envelopper dans `@media (prefers-reduced-motion: no-preference)` |
| A9 | `resources/views/admin/auth/login.blade.php:15` | 1.3.1 / 2.4.6 (A) | majeur | `<h1>` = « Enterprise Core » (la marque), le titre de page « Connexion » est en `<h2>` | `<h1>Connexion</h1>`, marque en `<p>`/logo ; ou inverser |
| A10 | `resources/views/admin/auth/mfa-enroll.blade.php:12` | 1.3.1 / 2.4.6 (A) | majeur | idem — « Configurer un authentificateur » en `<h2>` | idem |
| A11 | `resources/views/admin/auth/mfa-verify.blade.php:12` | 1.3.1 / 2.4.6 (A) | majeur | idem — « Vérification MFA » en `<h2>` | idem |
| A12 | `resources/views/admin/auth/invitation.blade.php:12` | 1.3.1 / 2.4.6 (A) | majeur | idem — « Activer mon compte » en `<h2>` | idem |
| A13 | `resources/views/vendor/scramble/docs.blade.php:2` | 3.1.2 (A) | mineur | `<html lang="en">` sur une application française (doc développeur, hors produit) | `lang="fr"` |
| A14 | `resources/views/vendor/scramble/scalar.blade.php:2` | 3.1.2 (A) | mineur | idem | `lang="fr"` |

### B — Formulaires (30 écarts)

| # | Fichier:ligne | Critère WCAG (niveau) | Gravité | Description | Correction proposée |
|---|---|---|---|---|---|
| B1 | `resources/views/components/admin/search-box.blade.php:15-21` | 1.3.1 / 3.3.2 / 4.1.2 (A) | majeur | Champ de recherche **sans `<label>`**, placeholder seul. Composant réutilisé 3× (`admin/organizations/index.blade.php:11`, `admin/wallets/index.blade.php:20`, `admin/operators/index.blade.php:31`) | `<label for="admin-search" class="sr-only">{{ $placeholder }}</label>` + `id` sur l'input |
| B2 | `resources/views/admin/projects/index.blade.php:22, 26` | 1.3.1 / 4.1.2 (A) | majeur | 2 `<label>` sans attribut `for` et sans association implicite (le `<input>` est dans un frère, pas dans le label) | `for="search"` / `for="organization_id"` + `id` sur les champs |
| B3 | `resources/views/admin/payments/index.blade.php:9, 18, 22` | 1.3.1 / 4.1.2 (A) | majeur | 3 `<label>` sans `for` (Projet, Canal, Statut) | idem |
| B4 | `resources/views/admin/notifications/index.blade.php:14` | 1.3.1 / 4.1.2 (A) | majeur | `<label>Statut</label>` sans `for` | `for="status"` + `id` |
| B5 | `resources/views/admin/audit-log/index.blade.php:13, 17, 21, 25, 29` | 1.3.1 / 4.1.2 (A) | majeur | 5 `<label>` sans `for` (Ressource, Acteur, Action, Depuis, Jusqu'au) | idem |
| B6 | `resources/views/admin/webhook-deliveries/dead.blade.php:37` | 1.3.1 / 4.1.2 (A) | majeur | `<label>Statut</label>` sans `for` | idem |
| B7 | `resources/views/admin/webhook-endpoints/create.blade.php:154` | 1.3.1 / 4.1.2 (A) | majeur | `<label>URL de réception</label>` (formulaire d'édition) sans `for` ; l'input ligne 155 n'a pas d'`id` | `for`/`id` (comme le formulaire de création ligne 212) |
| B8 | `resources/views/admin/webhook-endpoints/create.blade.php:164` | 1.3.1 (A) | majeur | `<label>Sur quoi être notifié ?</label>` orphelin au-dessus d'un groupe de cases à cocher | `<fieldset><legend>` |
| B9 | `resources/views/admin/webhook-endpoints/create.blade.php:229` | 1.3.1 (A) | majeur | idem (formulaire de création) | `<fieldset><legend>` |
| B10 | `resources/views/admin/organization-members/index.blade.php:62-67` | 4.1.2 (A) | majeur | `<select name="role">` **sans aucune étiquette** (ni label, ni `aria-label`) | `<label for="role-{{ $member->operatorId }}">` (visuellement caché) ou `aria-label="Rôle de {{ $member->email }}"` |
| B11 | `resources/views/admin/organization-members/index.blade.php:63` | **3.2.2 (A)** | majeur | `onchange="this.form.submit()"` : changement de contexte automatique (rechargement) sans avertissement préalable | Bouton « Appliquer » explicite, ou `onchange` + message « le rôle sera enregistré immédiatement » |
| B12 | `resources/views/admin/ledger-adjustments/create.blade.php:117` | 4.1.2 (A) | majeur | `<select name="source_kind">` sans nom accessible (seul le `<legend>` du fieldset porte le contexte) | `<label for="source_kind">` + `id` |
| B13 | `resources/views/admin/ledger-adjustments/create.blade.php:129` | 4.1.2 (A) | majeur | `<select name="destination_kind">` sans nom accessible | idem |
| B14 | `resources/views/admin/ledger-adjustments/create.blade.php:122` | 1.3.1 / 3.3.2 (A) | majeur | `source_reference` : **placeholder seul** comme étiquette | `<label for="source_reference">` |
| B15 | `resources/views/admin/ledger-adjustments/create.blade.php:134` | 1.3.1 / 3.3.2 (A) | majeur | `destination_reference` : placeholder seul | idem |
| B16 | `resources/views/admin/billing/invoices/index.blade.php:18` | 4.1.2 (A) | majeur | `<select name="status">` sans étiquette | `aria-label="Filtrer par statut"` |
| B17 | `resources/views/admin/billing/subscriptions/show.blade.php:82, 107, 164` | 1.3.1 / 3.3.2 (A) | majeur | 3 champs `reason` : placeholder seul (motif suspension / motif mois offert / motif annulation) | `<label for="…">` |
| B18 | `resources/views/admin/billing/subscriptions/show.blade.php:105, 123, 145, 147` | 1.3.1 / 3.3.2 (A) | majeur | 4 champs sans étiquette (`days`, `plan_code`, `seq`, `reference`) — placeholder seul ou rien | idem |
| B19 | `resources/views/admin/billing/subscriptions/show.blade.php:125, 166` | 4.1.2 (A) | majeur | 2 `<select>` (`effective`, `at_period_end`) sans étiquette | `aria-label` |
| B20 | `resources/views/admin/billing/invoices/show.blade.php:268, 285` | 1.3.1 / 3.3.2 (A) | majeur | 2 champs `reason` (annulation / irrécouvrable) : placeholder seul | idem |
| B21 | `resources/views/admin/billing/ledger.blade.php:60` | 1.3.1 / 3.3.2 (A) | majeur | Filtre `type` : placeholder seul | `<label for="ledger-type">` |
| B22 | `resources/views/admin/billing/usage.blade.php:16` | 1.3.1 / 3.3.2 (A) | majeur | Filtre `customer` : placeholder seul | idem |
| B23 | `resources/views/admin/portfolio/index.blade.php:28` | 1.3.1 / 3.3.2 (A) | majeur | Recherche `q` : placeholder seul | idem |
| B24 | `resources/views/admin/billing/customers/index.blade.php:20` | 1.3.1 / 3.3.2 (A) | majeur | Recherche `search` : placeholder seul | idem |
| B25 | `resources/views/admin/channel-sessions/index.blade.php:50` | 1.3.1 / 3.3.2 (A) | majeur | `phone_number` : placeholder `+2376XXXXXXXX` comme seule étiquette | idem |
| B26 | `resources/views/admin/api-keys/create.blade.php:107, 120, 141, 173, 179` | **1.4.11 (AA)** + 1.3.1 | majeur | 5 champs avec `border-slate-300` **sans la classe `border`** : Tailwind preflight force `border-width: 0` → **aucune bordure visible**, le champ n'est pas identifiable | Ajouter `border` (ou `border-2`) |
| B27 | *transversal — 30 occurrences* : `admin/auth/login.blade.php:38`, `admin/auth/mfa-enroll.blade.php:86`, `admin/auth/mfa-verify.blade.php:53`, `admin/auth/invitation.blade.php:34,46`, `admin/organizations/create.blade.php:28`, `admin/organizations/edit.blade.php:30,48`, `admin/projects/create.blade.php:32,51`, `admin/projects/edit.blade.php:29,52,66`, `admin/operators/create.blade.php:32,55`, `admin/operators/edit.blade.php:44,56,83`, `admin/organization-members/invite.blade.php:31,44`, `admin/channel-sessions/index.blade.php:84`, `admin/provider-applications/create.blade.php:116`, `admin/webhook-endpoints/create.blade.php:222,253` | 1.3.1 (A) / 3.3.1 (AA) | majeur | **Message d'erreur jamais relié au champ** (`@error` sans `aria-describedby`), `aria-invalid` absent : un lecteur d'écran ne sait pas quel champ est en erreur | Donner un `id` au `<p>` d'erreur (`id="err-name"`) + `aria-describedby="err-name"` + `aria-invalid="true"` sur le champ ; ou créer un composant `<x-admin.field>` |
| B28 | *transversal — 14 formulaires de la console* | 2.4.3 (A) / 3.3.1 (AA) | mineur | Aucun **résumé d'erreurs focusable** en tête de formulaire (`tabindex="-1"` + focus au chargement) | `<div role="alert" tabindex="-1" id="errors">` + `autofocus`/focus JS sur erreur |
| B29 | `resources/views/admin/reconciliation/new.blade.php:169` | 4.1.2 (AA) | mineur | `@disabled` (bouton retiré de l'ordre de tabulation) au lieu de `aria-disabled` | `aria-disabled="true"` + garde `disabled` sur l'action |
| B30 | `resources/views/admin/organization-members/invite.blade.php:23` (+ `admin/operators/create.blade.php:29` `autocomplete="off"`, `admin/operators/edit.blade.php:39` `autocomplete="off"`) | 1.3.5 (AA) | mineur | Champs email sans `autocomplete="email"` (ou explicitement `off` sur des identifiants) | `autocomplete="email"` |

### C — Modales, focus, clavier (20 écarts)

| # | Fichier:ligne | Critère WCAG (niveau) | Gravité | Description | Correction proposée |
|---|---|---|---|---|---|
| C1 | `resources/views/admin/organization-members/index.blade.php:85-107` | 4.1.2 (A) | **bloquant** | Modale de révocation : ni `role="dialog"`, ni `aria-modal="true"`, ni `aria-labelledby` → annoncée comme un simple formulaire | `<div role="dialog" aria-modal="true" aria-labelledby="…">` |
| C2 | `resources/views/admin/operators/index.blade.php:84-107` | 4.1.2 (A) | **bloquant** | idem | idem |
| C3 | `resources/views/admin/webhook-deliveries/dead.blade.php:137-160` | 4.1.2 (A) | **bloquant** | idem | idem |
| C4 | `resources/views/admin/webhook-endpoints/create.blade.php:117-146` | 4.1.2 (A) | **bloquant** | idem | idem |
| C5 | `resources/views/admin/api-keys/create.blade.php:99-128` | 4.1.2 (A) | **bloquant** | idem | idem |
| C6 | `resources/views/admin/provider-applications/create.blade.php:57-69` | 4.1.2 (A) | **bloquant** | idem | idem |
| C7 | `resources/views/admin/reconciliation/new.blade.php:103-126` | 4.1.2 (A) | **bloquant** | idem | idem |
| C8 | *transversal — les 7 modales ci-dessus* | **2.4.3 (A)** | **bloquant** | **Ni piège de focus, ni restitution du focus au déclencheur** ; le fond reste dans l'ordre de tabulation (le focus sort de la modale et se perd) ; pas d'`inert` sur le fond | Composant `<x-admin.confirm-dialog>` : `keydown` pour piéger Tab/Shift+Tab, focus sur le premier bouton à l'ouverture, `focus()` sur le déclencheur à la fermeture, `inert` sur `#main` |
| C9 | `resources/views/admin/audit-log/index.blade.php:71` | 4.1.2 (AA) | majeur | Bouton « Voir/Masquer ▸ » : **pas de `aria-expanded`**, pas de `aria-controls` | `:aria-expanded="expanded"` + `id` sur le bloc détaillé |
| C10 | `resources/views/admin/webhook-endpoints/create.blade.php:96` | 4.1.2 (AA) | majeur | « Modifier » (bascule un formulaire) sans `aria-expanded` | `:aria-expanded="editing"` |
| C11 | `resources/views/admin/webhook-endpoints/create.blade.php:100` | 4.1.2 (AA) | majeur | « Faire tourner le secret » (ouvre une modale) sans `aria-haspopup="dialog"` ni `aria-expanded` | `aria-haspopup="dialog"` + `:aria-expanded` |
| C12 | `resources/views/admin/webhook-endpoints/create.blade.php:113` | 4.1.2 (AA) | majeur | « Révoquer l'ancien secret » : idem | idem |
| C13 | `resources/views/admin/provider-applications/create.blade.php:56` | 4.1.2 (AA) | majeur | « Supprimer » : idem | idem |
| C14 | `resources/views/admin/organization-members/index.blade.php:79` | 4.1.2 (AA) | majeur | « Révoquer » : idem | idem |
| C15 | `resources/views/admin/operators/index.blade.php:78` | 4.1.2 (AA) | majeur | « Révoquer » : idem | idem |
| C16 | `resources/views/admin/reconciliation/new.blade.php:97` | 4.1.2 (AA) | majeur | « Réconcilier » : idem | idem |
| C17 | `resources/views/admin/api-keys/create.blade.php:94, 96` | 4.1.2 (AA) | majeur | « Faire tourner » / « Révoquer » : idem | idem |
| C18 | `resources/views/admin/webhook-deliveries/dead.blade.php:78-87` | 4.1.2 (A) | mineur | Bouton d'expansion : nom accessible par **`title` seul** (SVG sans `aria-hidden`), `:aria-expanded` présent mais **pas d'`aria-controls`** (le `<tr>` détaillé n'a pas d'`id`) | `aria-label="Afficher le corps envoyé et la réponse"` + `aria-hidden` sur le SVG + `id` + `aria-controls` |
| C19 | `resources/views/layouts/admin.blade.php:20-37` | 4.1.2 / 2.4.3 (A) | mineur | **`x-cloak` sans règle CSS** (`[x-cloak]{display:none}`) : les 7 modales s'affichent avant l'initialisation d'Alpine (flash) et leurs boutons sont focusables | Ajouter `[x-cloak]{display:none !important}` au `<style>` du layout |
| C20 | *transversal — ~60 `<svg>`* (hors `resources/views/layouts/admin.blade.php:288` qui le fait bien) | 1.1.1 (A) | mineur | Icônes décoratives sans `aria-hidden="true"` → bruit dans l'arbre d'accessibilité | `aria-hidden="true" focusable="false"` (le portail le fait déjà : `packages/portal/resources/views/partials/icon.blade.php:16`) |

### D — Tableaux (6 écarts)

| # | Fichier:ligne | Critère WCAG (niveau) | Gravité | Description | Correction proposée |
|---|---|---|---|---|---|
| D1 | `resources/views/components/admin/table.blade.php:30` | 1.3.1 (A) | majeur | **63 tableaux** (27 vues) sans `<caption>` ni `aria-label` sur `<table>` : en navigation « par tableau », ils sont tous nommés « table » | Ajouter une prop `caption` au composant + `<caption class="sr-only">{{ $caption }}</caption>` ; idem pour les 3 tableaux bruts |
| D2 | `resources/views/admin/provider-applications/create.blade.php:36-45` | 1.3.1 (A) | majeur | **Écart au design system** : `<table>` écrite à la main, `<th>` **sans `scope="col"`**, pas de `x-admin.table` | Basculer sur `x-admin.table` (comme les 26 autres vues) |
| D3 | `resources/views/admin/billing/ledger.blade.php:33-35` | 1.3.1 (A) | majeur | idem — `<th>` sans `scope="col"` (balance de vérification) | idem |
| D4 | `resources/views/admin/billing/ledger.blade.php:68-70` | 1.3.1 (A) | majeur | idem — `<th>` sans `scope="col"` (transactions) | idem |
| D5 | *transversal — 6 occurrences* : `admin/webhook-deliveries/dead.blade.php:53`, `admin/notifications/index.blade.php:30`, `admin/wallets/index.blade.php:44`, `admin/projects/exports.blade.php:25`, `admin/billing/subscriptions/index.blade.php:16`, `admin/billing/invoices/index.blade.php:34` | 1.3.1 (A) | mineur | Colonne d'action / en-tête vide (`''`) → en-tête de tableau vide exposé | `aria-label="Actions"` sur le `<th>` ou en-tête non vide |
| D6 | `resources/views/admin/portfolio/index.blade.php:36, 42, 70` | 1.3.1 (A) | mineur | `<tr>` passés **sans `<tbody>`** au composant (le composant ne l'exige pas) — HTML invalide, le navigateur insère un `<tbody>` implicite | Encapsuler les lignes dans `<tbody>` comme les 26 autres vues |

### E — Contraste et usage de la couleur (21 écarts)

| # | Fichier:ligne | Critère WCAG (niveau) | Gravité | Description | Correction proposée |
|---|---|---|---|---|---|
| E1 | `resources/views/layouts/admin.blade.php:105, 114, 140, 200, 221` | 1.4.3 (AA) | majeur | Libellés de section de la barre latérale (`text-slate-500` #64748b) sur `bg-slate-900` (#0f172a) = **3.75:1** (texte normal 15,75 px → seuil 4.5:1) | `text-slate-400` (#94a3b8 → 6.96:1) ou `text-slate-300` |
| E2 | `resources/views/admin/auth/invitation.blade.php:40` | 1.4.3 (AA) | majeur | « (facultatif) » en `text-slate-400` sur blanc = **2.56:1** | `text-slate-500` (4.76:1) |
| E3 | `resources/views/admin/provider-applications/create.blade.php:107` | 1.4.3 (AA) | majeur | idem — « (facultatif) » | idem |
| E4 | `resources/views/admin/money-exceptions/show.blade.php:130` | 1.4.3 (AA) | majeur | Bouton « Valider et écrire au grand livre » : `bg-emerald-600` + texte blanc = **3.77:1** (`text-sm font-semibold` = 15,75 px, pas du « grand texte ») | `bg-emerald-700` (5.48:1) ou texte `text-slate-900` |
| E5 | `resources/views/admin/ledger-adjustments/show.blade.php:99` | 1.4.3 (AA) | majeur | idem | idem |
| E6 | `resources/views/admin/webhook-endpoints/create.blade.php:103` | 1.4.3 (AA) | majeur | « Réactiver » en `text-emerald-600` sur blanc = **3.77:1** | `text-emerald-700` |
| E7 | `resources/views/admin/webhook-deliveries/dead.blade.php:162` | 1.4.3 (AA) | mineur | `—` en `text-slate-400` = 2.56:1 | `text-slate-500` |
| E8 | `resources/views/admin/audit-log/index.blade.php:101` | 1.4.3 (AA) | mineur | idem | idem |
| E9 | `resources/views/admin/billing/subscriptions/index.blade.php:23` | 1.4.3 (AA) | mineur | « v2 » en `text-slate-400` | idem |
| E10 | `resources/views/admin/billing/subscriptions/show.blade.php:11` | 1.4.3 (AA) | mineur | idem | idem |
| E11 | `resources/views/admin/billing/customers/index.blade.php:44` | 1.4.3 (AA) | mineur | `—` en `text-slate-400` | idem |
| E12 | `resources/views/admin/billing/invoices/show.blade.php:97` | 1.4.3 (AA) | mineur | Taux de taxe `(18,00 %)` en `text-slate-400` | idem |
| E13 | `resources/views/admin/billing/invoices/receipts.blade.php:97` | 1.4.3 (AA) | mineur | `—` en `text-slate-400` | idem |
| E14 | `resources/views/admin/billing/indicators.blade.php:76` | 1.4.3 (AA) | mineur | Dates de début/fin de courbe en `text-xs text-slate-400` | idem |
| E15 | `resources/views/admin/billing/ledger.blade.php:85` | 1.4.3 (AA) | mineur | Référence de compte en `text-slate-400` | idem |
| E16 | `resources/views/admin/ledger-adjustments/create.blade.php:46` | 1.4.3 (AA) | mineur | Id de projet en `text-slate-400` | idem |
| E17 | `resources/views/admin/portfolio/index.blade.php:54` | 1.4.3 (AA) | mineur | `—` en `text-slate-400` | idem |
| E18 | `resources/views/admin/billing/customers/show.blade.php:37` | **1.4.1 (A)** | majeur | Barre de quota : l'état de seuil (80 %, 100 %) est porté **par la couleur seule** (`bg-emerald/amber/rose-500`) | Ajouter `role="progressbar"` + `aria-valuenow` + `aria-valuetext` (ou un `<progress>` comme le portail) + libellé textuel de l'état |
| E19 | `resources/views/admin/billing/usage.blade.php:33` | **1.4.1 (A)** | majeur | idem (barre de compteur) | idem |
| E20 | `resources/views/admin/payments/partials/journey.blade.php:16-22` | 1.4.1 (A) | mineur | Pastille de ton colorée seule (l'information est doublée par le libellé texte → non bloquant) | `aria-hidden="true"` sur la pastille |
| E21 | *transversal — ~90 occurrences dans la console* | 1.4.3 (AA) | mineur | `text-slate-500` sur blanc = 4.76:1 et sur `bg-slate-50` = 4.55:1 → **conforme mais sans marge** (la racine `font-size:18px` ne change rien au ratio) | Passer à `text-slate-600` (7.58:1) pour les libellés de section |

### F — Cible tactile, mouvement, reflow (7 écarts)

| # | Fichier:ligne | Critère WCAG (niveau) | Gravité | Description | Correction proposée |
|---|---|---|---|---|---|
| F1 | `resources/views/admin/projects/exports.blade.php:43` | 2.5.8 (AA, nouveau en 2.2) | majeur | Bouton « Télécharger » en `text-sm` sans padding → hauteur **22,5 px** (< 24 px) | `px-2 py-1` (≥ 30 px) |
| F2 | `resources/views/admin/channel-sessions/index.blade.php:52` | 2.5.8 (AA) | majeur | « Code d'appairage » `text-sm` sans padding → 22,5 px | idem |
| F3 | `resources/views/admin/webhook-endpoints/create.blade.php:100, 113` | 2.5.8 (AA) | majeur | « Faire tourner le secret » / « Révoquer l'ancien secret » `text-sm` sans padding → 22,5 px | idem |
| F4 | `resources/views/admin/billing/invoices/receipts.blade.php:84, 91` | 2.5.8 (AA) | majeur | Boutons `px-2 py-1 text-xs` → hauteur **22 px** (< 24 px) | `px-2 py-1.5` |
| F5 | *transversal* : `admin/organizations/index.blade.php:48,54,59`, `admin/billing/customers/index.blade.php:70,76,85`, `admin/billing/plans/index.blade.php:36,73`, `components/admin/search-box.blade.php:27` | 2.5.8 (AA) | mineur | Liens d'action `text-sm` sans padding (22,5 px) — **l'exception d'espacement** de la SC 2.5.8 est applicable (écart horizontal ≥ 12 px) | `inline-block py-1` pour être explicite |
| F6 | `resources/views/layouts/admin.blade.php:67` | 1.4.10 (AA) | majeur | Shell `flex h-screen overflow-hidden` : à 400 % / 320 px, tout débordement **est tronqué** (pas de scroll horizontal de secours) | Retirer `overflow-hidden` du shell ou passer le débordement sur `<main>` (`overflow-y-auto overflow-x-visible`) |
| F7 | `resources/views/components/admin/pagination.blade.php:24-41` | 4.1.2 (AA) | mineur | `aria-disabled="true"` sur un `<a href>` : l'activation au clavier **navigate quand même** (le href reste actif) | Neutraliser le `href` (`aria-disabled` + `tabindex="-1"` + `onclick` bloqué) |

### G — Portail client (7 écarts)

| # | Fichier:ligne | Critère WCAG (niveau) | Gravité | Description | Correction proposée |
|---|---|---|---|---|---|
| G1 | `packages/portal/resources/views/home.blade.php:51` | 4.1.2 (A) | mineur | `<span class="button button--sm">Payer</span>` : faux bouton (non focusable) au sein d'un lien | `<span class="button button--sm" aria-hidden="true">Payer</span>` (le lien porte déjà l'action) |
| G2 | `packages/portal/resources/views/login-verify.blade.php:17` | 4.1.2 (AA) | mineur | `aria-describedby="{{ isset($error) ? 'code-error' : '' }}"` → attribut vide quand il n'y a pas d'erreur | ternary sans branche vide |
| G3 | `packages/portal/resources/views/layout.blade.php:84-88` | 1.3.1 (A) | mineur | Aucun landmark `contentinfo` (pas de `<footer>`) | `<footer>` dans la colonne latérale |
| G4 | `packages/portal/resources/views/plans.blade.php:40` | 4.1.2 (A) | mineur | `<span class="button … is-static">Offre actuelle</span>` : élément non interactif présenté comme un bouton | `aria-disabled="true"` ou style neutre |
| G5 | `packages/portal/resources/views/usage.blade.php:24` (CSS `public/assets/portal/portal.css:224`) | 1.1.1 (A) | mineur | Le `::before { content: "✓" }` est du contenu généré (annoncé par certains lecteurs d'écran) ; seul l'état « non inclus » a un `sr-only` | `aria-hidden` sur le pseudo-élément ou `list-style` sans contenu généré |
| G6 | `packages/portal/resources/views/subscriptions.blade.php:10` | 2.4.6 (A) | mineur | Un `<h2>` par abonnement, sans `id` ni regroupement → 8 titres de même niveau, indistincts à la navigation par titres | `<h2 id="sub-{{ $sub->id }}">` + `aria-labelledby` sur la `<section>` |
| G7 | `packages/portal/resources/views/invoices.blade.php:6-22` | 1.3.1 (A) | mineur | Liste des factures sans titre de section (le `<h1>` est suivi directement de liens) | `<section aria-labelledby="…">` + `<h2 id="…">Factures</h2>` |

### H — Messages d'état (4.1.3) (13 écarts)

| # | Fichier:ligne | Critère WCAG (niveau) | Gravité | Description | Correction proposée |
|---|---|---|---|---|---|
| H1 | `resources/views/admin/organization-members/index.blade.php:10` | 4.1.3 (AA) | majeur | Bandeau « Invitation créée » sans `role="status"` | `role="status"` |
| H2 | `resources/views/admin/organization-members/index.blade.php:27` | 4.1.3 (AA) | majeur | Bandeau d'erreurs sans `role="alert"` | `role="alert"` |
| H3 | `resources/views/admin/operators/index.blade.php:15` | 4.1.3 (AA) | majeur | Bandeau invitation sans `role="status"` | idem |
| H4 | `resources/views/admin/projects/show.blade.php:8` | 4.1.3 (AA) | majeur | Bandeau « clé API générée » sans `role="status"` | idem |
| H5 | `resources/views/admin/payments/show.blade.php:92` | 4.1.3 (AA) | majeur | Bandeau d'erreur paiement sans `role="alert"` | idem |
| H6 | `resources/views/admin/channel-sessions/index.blade.php:16, 19` | 4.1.3 (AA) | majeur | Bandeaux succès/erreur sans `role` | `role="status"` / `role="alert"` |
| H7 | `resources/views/admin/webhook-deliveries/dead.blade.php:12` | 4.1.3 (AA) | majeur | Bandeau d'erreur de rejeu sans `role="alert"` | idem |
| H8 | `resources/views/admin/webhook-deliveries/show.blade.php:21` | 4.1.3 (AA) | majeur | idem | idem |
| H9 | `resources/views/admin/webhook-endpoints/create.blade.php:16, 27` | 4.1.3 (AA) | majeur | Bandeaux succès/erreur sans `role` | `role="status"` / `role="alert"` |
| H10 | `resources/views/admin/api-keys/create.blade.php:30, 38` | 4.1.3 (AA) | majeur | Bandeaux erreur/succès sans `role` | idem |
| H11 | `resources/views/admin/provider-applications/create.blade.php:13, 17` | 4.1.3 (AA) | majeur | idem | idem |
| H12 | *transversal — 7 vues* : `admin/money-exceptions/show.blade.php:21,24`, `admin/ledger-adjustments/show.blade.php:21,24`, `admin/ledger-adjustments/create.blade.php:22`, `admin/reconciliation/new.blade.php:17`, `admin/billing/invoices/show.blade.php:14`, `admin/billing/invoices/form.blade.php:20`, `admin/billing/invoices/receipts.blade.php:34` | 4.1.3 (AA) | majeur | Bandeaux d'erreur de formulaire sans `role="alert"` | idem |
| H13 | `resources/views/layouts/admin.blade.php:312` | 4.1.3 (AA) | majeur | Le flash de succès utilise `role="status"` **dans une page rechargée** : la région live n'existe pas avant le rendu → la plupart des lecteurs d'écran **n'annoncent pas** le message | Rendre le conteneur live **toujours présent** (vide) dans le layout et y injecter le message ; ou `role="alert"` sur le flash d'erreur, `role="status"` sur une région persistante |

### I — Hors périmètre produit (1 écart)

| # | Fichier:ligne | Critère WCAG (niveau) | Gravité | Description | Correction proposée |
|---|---|---|---|---|---|
| I1 | `packages/payment/resources/views/simulator-checkout.blade.php:20-49` | 1.3.1 / 2.4.6 (A) | mineur | Aucun `<h1>`, aucun `<main>`, 4 `<button>` sans `type="submit"` explicite (page de simulateur, staging uniquement) | `<main>` + `<h1>` + `type="submit"` |

---

## 2. Points systémiques — les 5 chantiers qui règlent le plus d'écarts

**1. Un composant de dialogue de confirmation (`<x-admin.confirm-dialog>`) — 23 écarts (C1→C8, C11→C17).**
Les 7 modales sont des copies quasi mot pour mot du même overlay Alpine (`x-show` + `fixed inset-0 z-50` + `<form>` + Annuler/Confirmer). Un seul composant (rôle `dialog`, `aria-modal`, `aria-labelledby`, piège de focus, restitution du focus, `inert` sur le fond, `aria-haspopup="dialog"` sur le déclencheur) supprime 2 écarts bloquants par vue et 9 écarts majeurs (`aria-haspopup` / `aria-expanded` sur les 9 boutons déclencheurs). Premier chantier : 2 bloquants + 9 majeurs d'un coup.

**2. Un composant de champ de formulaire (`<x-admin.field>`) — 35 écarts (B1→B27, B30).**
`label for` + `id` + `aria-describedby` vers le message d'erreur + `aria-invalid="true"` + `required` / `autocomplete`, en un seul endroit. Couvre : les 30 `@error()` non reliés (B27), les 15 `<label>` sans `for` (B2→B9), les 17 champs sans étiquette (B10, B12→B26), le composant `search-box` (B1) et les `autocomplete` (B30). Seul moyen d'atteindre 100 % des formulaires : **28 des 38 formulaires** de la console sont écrits à la main, hors design system.

**3. Le design system admin passe de 5 à 8 composants : `table` (caption), `field` (label / erreur), `dialog` (focus) + `alert` (role), `meter` (progressbar), `status-badge` accessible — 30 écarts supplémentaires (D1, D2→D4, E18, E19, H1→H13, B28).**
- `x-admin.table` gagne une prop `caption` → **63 tableaux** nommés (D1) et suppression des 3 tableaux bruts hors design system (D2→D4).
- `x-admin.alert` (rôle `status` / `alert` + `tabindex="-1"` + focus au chargement) → **13 bandeaux** (H1→H13).
- `x-admin.meter` (sur le modèle de `packages/portal/resources/views/partials/meter.blade.php`, déjà conforme : `<progress>` + `aria-valuetext`) → 2 barres de quota color-only (E18, E19).

**4. Un correctif CSS unique dans `resources/views/layouts/admin.blade.php` — 30 écarts (A6, A7, A8, C19, E1, E21, D5, F7).**
Dans le `<style>` du layout : (a) halo 25 % → `outline` ≥ 3:1 (**A7**, seul écart de contraste transverse) ; (b) `[x-cloak]{display:none!important}` → C19 ; (c) `@media (prefers-reduced-motion: no-preference)` → A8 ; (d) `.skip` + lien d'évitement → A6 ; (e) `text-slate-400` sur les libellés de la barre latérale → E1 ; (f) `text-slate-600` par défaut pour les libellés de section → E21. Un seul fichier, un seul passage en revue.

**5. Une revue « couleur et cible tactile » sur les tokens de la console — 24 écarts (E2→E17, E20, F1→F5).**
Règle applicable partout : `text-slate-400` interdit sur fond clair (2.56:1) → `text-slate-500` (4.76:1) ; `bg-emerald-600` / `text-emerald-600` → `-700` (3.77 → 5.48:1) ; tout `text-sm` / `text-xs` cliquable sans padding gagne `py-1` (22,5 px → 30 px). Vérifiable par un lint de classes — un garde-fou CI du type de `make arch` (`grep` sur `text-slate-400`, `text-emerald-600`, `<button … text-xs` sans `py-`).

> Bonus à 1 ligne : `packages/portal/src/Application/Support/PortalPalette.php` garantit déjà 4.5:1 pour `--brand-text` — **c'est le modèle à copier**, et il n'y a rien à corriger côté palette.

---

## 3. Plan de correction ordonné

| Ordre | Chantier | Écarts | Fichiers | Pourquoi en premier |
|---|---|---|---|---|
| **1** | **Focus visible des champs** (`resources/views/layouts/admin.blade.php:32-36`) : `outline: 2px solid #4338ca; outline-offset: 2px` | A7 (1 bloquant) | 1 | Correction d'**une ligne** qui débloque le critère 2.4.11 (nouveau en 2.2) sur **tous** les champs de la console. Critère le plus contrôlé en audit externe. |
| **2** | **Composant `<x-admin.confirm-dialog>`** + remplacement des 7 modales + `aria-haspopup` / `aria-expanded` sur les 9 déclencheurs | C1→C8, C11→C17 (2 bloq. + 9 maj.) | 8 | Supprime les 2 derniers bloquants. Ne peut pas être traité vue par vue (copies divergentes). |
| **3** | **Composant `<x-admin.field>`** (label / erreur / `aria-invalid` / `autocomplete`) + migration des 30 `@error` et des 15 `<label>` orphelins | B1→B27, B30 (27 maj.) | 24 | Le plus gros volume : 27 majeurs. Débloque aussi la revue 3.3.1 / 1.3.1 sur tous les formulaires. |
| **4** | **Bandeaux d'état** : `role="status"` / `role="alert"` (13) + région live persistante pour le flash du layout (H13) | H1→H13 (13 maj.) | 12 | 4.1.3 est un critère AA explicitement audité ; H13 (région live persistante) est un piège classique que l'audit automatisé ne voit pas. |
| **5** | **Contraste** : `text-slate-400`→`500` (13), `emerald-600`→`700` (3), `text-slate-500`→`600` sur fond sombre (1) | E1→E3, E6, E20 (4 maj. + 13 min.) | 16 | 1.4.3 : le plus fréquemment cité dans un audit AA. Le lot « site clair » est purement mécanique (remplacement de classe). |
| **6** | **Tableaux** : prop `caption` sur `x-admin.table` (63 tableaux) + conversion des 3 tableaux bruts (`admin/provider-applications/create.blade.php:36`, `admin/billing/ledger.blade.php:33,68`) | D1→D4 (4 maj.) | 29 | Un composant, un impact massif. Doit suivre le chantier 3. |
| **7** | **Cible tactile** : `py-1` sur les 8 boutons / liens < 24 px | F1→F4 (4 maj.) | 4 | 2.5.8 est un critère **nouveau en WCAG 2.2** → vérifié spécifiquement à la mise en ligne. |
| **8** | **Barres de quota accessibles** (`role="progressbar"` + `aria-valuetext`) — modèle `packages/portal/resources/views/partials/meter.blade.php` | E18, E19 (2 maj.) | 2 | 1.4.1, et le portail fournit déjà la solution exacte. |
| **9** | **Landmarks & titres** : `aria-label` sur le `<nav>`, lien d'évitement, `<footer>`, `h1` = titre de page sur les 4 pages publiques, sous-titre mobile | A1→A6, A9→A12 (7 maj.) | 7 | 2.4.1 / 1.3.1 : nécessaires à la navigation clavier et lecteur d'écran ; faible risque de régression. |
| **10** | **Barres d'action** : `aria-haspopup` sur les déclencheurs restants, `aria-expanded` sur les bascules (audit-log, webhook-endpoints) | C9→C12 (4 maj.) | 2 | Dépend du composant dialogue (ordre 2). |
| **11** | **Formulaires** : `onchange` du select de rôle → bouton explicite ; `autocomplete` ; `aria-disabled` | B10, B11, B29, B30 (2 maj. + 2 min.) | 4 | 3.2.2 est un critère **A** (bloquant au sens WCAG). |
| **12** | **Nettoyage** : `x-cloak` CSS, `aria-hidden` sur ~60 SVG, `<tbody>` explicite, `scope="row"`, colonnes d'en-tête vides, `<footer>` portail, `aria-describedby=""` | C19, C20, D5, D6, E20, G2→G7, F5, F7 | 12 | Cosmétique / faible risque ; à faire en dernier pour ne pas grever le churn des gros chantiers. |

**Effet cumulatif** : après les ordres 1→4 (≈ 8 000 caractères de code, 24 fichiers), **63 des 119 écarts (9 bloquants + 54 majeurs) sont soldés**. Les ordres 5→8 ferment les 15 restants de niveau AA ; les ordres 9→12 ne contiennent que des mineurs.

---

## 4. Conformité constatée (à ne pas casser)

| Point | Fichier | Conformité |
|---|---|---|
| `<html lang="fr">`, `<title>`, `<meta viewport>` (sans `maximum-scale`) | `resources/views/layouts/admin.blade.php:2-7`, `packages/portal/resources/views/layout.blade.php:2-8` | 3.1.1 / 2.4.2 / 1.4.4 ✓ |
| Lien d'évitement + `<main id="main" tabindex="-1">` | `packages/portal/resources/views/layout.blade.php:14, 85` | 2.4.1 ✓ |
| `<nav aria-label="Navigation principale">`, `aria-current="page"` | `packages/portal/resources/views/layout.blade.php:36, 54` | 1.3.1 ✓ |
| `<caption class="sr-only">` + `scope="col"` + `scope="row"` | `packages/portal/resources/views/invoice.blade.php:18-34` | 1.3.1 ✓ (modèle à copier pour la console) |
| `aria-describedby` vers l'erreur + `aria-invalid` + `role="alert"` | `packages/portal/resources/views/invoice.blade.php:13, 64` | 1.3.1 / 3.3.1 ✓ |
| `<progress>` + `aria-valuetext` + état `sr-only` | `packages/portal/resources/views/partials/meter.blade.php:21-25` | 1.4.1 ✓ (modèle pour E18 / E19) |
| `aria-hidden="true" focusable="false"` sur les SVG | `packages/portal/resources/views/partials/icon.blade.php:16` | 1.1.1 ✓ |
| `:focus-visible { outline: 3px solid var(--focus) }` | `public/assets/portal/portal.css:72` | 2.4.11 ✓ (le portail est conforme, la console ne l'est pas) |
| `prefers-reduced-motion` respecté | `public/assets/portal/portal.css:244-250` | 2.3.3 ✓ |
| Contraste de la couleur de marque garanti ≥ 4.5:1 | `packages/portal/src/Application/Support/PortalPalette.php:30, 111-119` | 1.4.3 ✓ |
| `<svg role="img" aria-label>`, `<section aria-labelledby>` | `resources/views/admin/billing/indicators.blade.php:47, 70, 87, 107` | 1.1.1 / 1.3.1 ✓ |
| État texte « (fait) / (à faire) » en `sr-only` | `resources/views/admin/billing/indicators.blade.php:115` | 1.4.1 ✓ |
| `role="status"` / `role="alert"` sur les flash du layout | `resources/views/layouts/admin.blade.php:312, 320` | 4.1.3 partiellement (cf. H13) |
| `<th scope="col">` sur les 63 tableaux via `x-admin.table` | `resources/views/components/admin/table.blade.php:34` | 1.3.1 ✓ |
| `aria-disabled` (et non `disabled`) sur les liens de pagination | `resources/views/components/admin/pagination.blade.php:30, 39` | 4.1.2 ✓ (réserve F7) |
| Aucun `<div>` / `<span>` cliquable : tous les déclencheurs sont des `<button type="button">` | 21 occurrences `@click=` dans la console | 2.1.1 ✓ |
| Aucune colonne masquée par `hidden md:block` (donc pas de contenu perdu au responsive) | — | 1.4.10 ✓ |
| `x-admin.table` encapsule le tableau dans `overflow-x-auto` (défilement horizontal limité au tableau) | `resources/views/components/admin/table.blade.php:29` | 1.4.10 ✓ |
| `text-slate-500` sur `bg-slate-50` = 4.55:1 (en-têtes de `x-admin.table`) | `resources/views/components/admin/table.blade.php:34` | 1.4.3 ✓ (limite, cf. E21) |

### Arbre d'accessibilité — console admin (état actuel → cible)

**Actuel** : `document` → `banner` (mobile seulement) → `generic` (header mobile) ; `complementary` (aside) → `navigation` (nav **sans nom**) → 15 `link` ; `generic` (bloc titre) → `navigation "Fil d'Ariane"` → 4 `link` ; `main` → `heading level 1` (titre de page) → `heading level 2`… ; `form` (filtres) → `textbox` **sans nom** ; `table` (sans nom) → `columnheader` × N → `cell` ; `button` (déclencheurs) → `form` (la modale, **sans rôle de dialogue**).

**Cible** : `document` → `banner` → `navigation "Navigation principale"` → `link` ; `navigation "Fil d'Ariane"` → `link` / `page` ; `main` → `heading level 1` → `region` (nombrée par `aria-labelledby`) → `table "…"` → `columnheader` / `rowheader` / `cell` ; `dialog "Confirmer…"` (modal) → `heading` + `button "Annuler"` + `button "Confirmer"` ; `status` (flash) ; `alert` (erreurs).

### Arbre d'accessibilité — portail client (conforme)

`document` → `banner` → `link "Aller au contenu"` (skip link) → `navigation "Navigation principale"` → `link` (avec `aria-current="page"`) ; `main#main[tabindex=-1]` → `heading level 1` → `region` → `table "Détail de la facture"` → `columnheader` / `rowheader` ; `form` → `group "Moyen de paiement"` → `radio` ; `textbox "Numéro de téléphone du compte"` `aria-describedby="phone-hint"` ; `alert` (erreur, reliée au champ) ; `progress` `aria-valuetext` + `meter` ; `form "Filtrer par…"`.

---

## 5. Décompte par fichier et total général

| Fichier | Écarts | Dont bloquants / majeurs |
|---|---|---|
| `resources/views/layouts/admin.blade.php` | 13 | 1 / 6 |
| `resources/views/admin/webhook-endpoints/create.blade.php` | 10 | 1 / 8 |
| `resources/views/admin/organization-members/index.blade.php` | 6 | 1 / 5 |
| `resources/views/admin/webhook-deliveries/dead.blade.php` | 5 | 1 / 4 |
| `resources/views/admin/ledger-adjustments/create.blade.php` | 5 | 0 / 5 |
| `resources/views/admin/provider-applications/create.blade.php` | 5 | 1 / 4 |
| `resources/views/admin/api-keys/create.blade.php` | 4 | 1 / 3 |
| `resources/views/admin/billing/ledger.blade.php` | 4 | 0 / 4 |
| `resources/views/admin/billing/subscriptions/show.blade.php` | 4 | 0 / 4 |
| `resources/views/admin/audit-log/index.blade.php` | 3 | 0 / 3 |
| `resources/views/admin/operators/index.blade.php` | 3 | 1 / 2 |
| `resources/views/admin/channel-sessions/index.blade.php` | 3 | 0 / 3 |
| `resources/views/admin/reconciliation/new.blade.php` | 3 | 1 / 2 |
| `resources/views/admin/portfolio/index.blade.php` | 3 | 0 / 3 |
| `resources/views/admin/billing/customers/index.blade.php` | 2 | 0 / 2 |
| `resources/views/admin/billing/invoices/show.blade.php` | 2 | 0 / 2 |
| `resources/views/admin/billing/invoices/receipts.blade.php` | 2 | 0 / 2 |
| `resources/views/admin/billing/usage.blade.php` | 2 | 0 / 2 |
| `resources/views/admin/auth/invitation.blade.php` | 2 | 0 / 2 |
| `resources/views/admin/notifications/index.blade.php` | 1 | 0 / 1 |
| `resources/views/admin/projects/index.blade.php` | 1 | 0 / 1 |
| `resources/views/admin/projects/show.blade.php` | 1 | 0 / 1 |
| `resources/views/admin/projects/exports.blade.php` | 1 | 0 / 1 |
| `resources/views/admin/payments/index.blade.php` | 1 | 0 / 1 |
| `resources/views/admin/payments/show.blade.php` | 1 | 0 / 1 |
| `resources/views/admin/payments/partials/journey.blade.php` | 1 | 0 / 1 |
| `resources/views/admin/organization-members/invite.blade.php` | 1 | 0 / 1 |
| `resources/views/admin/money-exceptions/show.blade.php` | 1 | 0 / 1 |
| `resources/views/admin/ledger-adjustments/show.blade.php` | 1 | 0 / 1 |
| `resources/views/admin/billing/customers/show.blade.php` | 1 | 0 / 1 |
| `resources/views/admin/billing/subscriptions/index.blade.php` | 1 | 0 / 1 |
| `resources/views/admin/billing/invoices/index.blade.php` | 1 | 0 / 1 |
| `resources/views/admin/billing/indicators.blade.php` | 1 | 0 / 1 |
| `resources/views/admin/webhook-deliveries/show.blade.php` | 1 | 0 / 1 |
| `resources/views/admin/auth/login.blade.php` | 1 | 0 / 1 |
| `resources/views/admin/auth/mfa-enroll.blade.php` | 1 | 0 / 1 |
| `resources/views/admin/auth/mfa-verify.blade.php` | 1 | 0 / 1 |
| `resources/views/components/admin/table.blade.php` | 1 | 0 / 1 |
| `resources/views/components/admin/search-box.blade.php` | 1 | 0 / 1 |
| `resources/views/components/admin/pagination.blade.php` | 1 | 0 / 1 |
| `resources/views/vendor/scramble/docs.blade.php` | 1 | 0 / 1 |
| `resources/views/vendor/scramble/scalar.blade.php` | 1 | 0 / 1 |
| `packages/portal/resources/views/layout.blade.php` | 1 | 0 / 0 |
| `packages/portal/resources/views/home.blade.php` | 1 | 0 / 0 |
| `packages/portal/resources/views/login-verify.blade.php` | 1 | 0 / 0 |
| `packages/portal/resources/views/plans.blade.php` | 1 | 0 / 0 |
| `packages/portal/resources/views/subscriptions.blade.php` | 1 | 0 / 0 |
| `packages/portal/resources/views/invoices.blade.php` | 1 | 0 / 0 |
| `packages/portal/resources/views/usage.blade.php` | 1 | 0 / 0 |
| `packages/payment/resources/views/simulator-checkout.blade.php` | 1 | 0 / 0 |
| **Transversaux (8 écarts sans fichier unique)** | **8** | 2 / 5 |
| **Fichiers sans écart (38)** | **0** | — |

Fichiers sans écart : `resources/views/components/admin/stat.blade.php`, `resources/views/components/admin/status-badge.blade.php`, `resources/views/admin/dashboard.blade.php`, `resources/views/admin/organizations/index.blade.php`, `resources/views/admin/organizations/create.blade.php`, `resources/views/admin/organizations/edit.blade.php`, `resources/views/admin/projects/create.blade.php`, `resources/views/admin/projects/edit.blade.php`, `resources/views/admin/notifications/show.blade.php`, `resources/views/admin/wallets/index.blade.php`, `resources/views/admin/wallets/ledger.blade.php`, `resources/views/admin/operators/create.blade.php`, `resources/views/admin/operators/edit.blade.php`, `resources/views/admin/channel-sessions/show.blade.php`, `resources/views/admin/webhook-events/index.blade.php`, `resources/views/admin/money-exceptions/index.blade.php`, `resources/views/admin/ledger-adjustments/index.blade.php`, `resources/views/admin/billing/customers/form.blade.php`, `resources/views/admin/billing/plans/index.blade.php`, `resources/views/admin/billing/plans/form.blade.php`, `resources/views/admin/billing/invoices/form.blade.php`, `resources/views/admin/billing/settings.blade.php`, `resources/views/admin/billing/portal.blade.php`, `resources/views/vendor/scramble/dev-tools.blade.php`, `packages/portal/resources/views/{invoice,payments,profile,login,error,signed-out,session-expired,link-invalid,payment-started}.blade.php`, `packages/portal/resources/views/partials/{icon,meter,guest-brand}.blade.php`.

### Total général par bloc

| Bloc | Bloquant | Majeur | Mineur | Total |
|---|---|---|---|---|
| A — Structure / landmarks / document | 1 | 8 | 5 | 14 |
| B — Formulaires | 0 | 27 | 3 | 30 |
| C — Modales / focus / clavier | 8 | 9 | 3 | 20 |
| D — Tableaux | 0 | 4 | 2 | 6 |
| E — Contraste / couleur | 0 | 8 | 13 | 21 |
| F — Cible tactile / reflow | 0 | 5 | 2 | 7 |
| G — Portail client | 0 | 0 | 7 | 7 |
| H — Messages d'état | 0 | 13 | 0 | 13 |
| I — Hors périmètre produit | 0 | 0 | 1 | 1 |
| **Total** | **9** | **74** | **36** | **119** |

**Répartition par périmètre produit** : console d'administration **104** écarts (9 bloquants, 74 majeurs, 21 mineurs) sur 63 vues ; portail client **7** écarts (tous mineurs) sur 19 vues ; simulateur de paiement **1** ; vendor Scramble **2**.

**Note de périmètre à acter** : `packages/admin/resources/views/**` (chemin annoncé dans la commande) est vide — le design system de référence est `resources/views/layouts/admin.blade.php` + `resources/views/components/admin/*`. Toute correction de design system doit donc porter sur `resources/views/`, pas sur `packages/admin/`. Par ailleurs, 28 des 38 formulaires et 3 des 66 tableaux de la console sont **écrits hors design system** : c'est la cause racine des écarts B et D, et la condition pour que les lots 2→6 ne se reproduisent pas.

---

## Cartouche de signature

| Champ | Valeur |
|---|---|
| **Date de l'audit** | 2026-10-29 |
| **Auteur** | Audit d'accessibilité WCAG 2.2 AA (agent Claude — rôle Senior Accessibility Architect), sous la supervision du coordinateur `vcam-core` |
| **Référentiel** | WCAG 2.2 niveaux A + AA (W3C, 2023) ; méthodes H39, H42, H43, H44, H57, ARIA 1.2, APG (dialog modal, disclosure, tabs) |
| **Périmètre audité** | `resources/views/**` (66 vues), `packages/portal/resources/views/**` (19 vues) — 85 vues au total ; hors périmètre produit traité en annexe : `packages/payment/resources/views/simulator-checkout.blade.php` (1 vue) |
| **Fichiers lus hors vues (référence)** | `public/assets/portal/portal.css`, `packages/portal/src/Application/Support/PortalPalette.php`, `CLAUDE.md`, `docs/architecture/vue-ensemble.md` |
| **Mode** | Lecture seule — aucun fichier du dépôt modifié par l'audit ; aucune commande base de données |
| **Écarts bloquants** | **9** |
| **Écarts majeurs** | **74** |
| **Écarts mineurs** | **36** |
| **Total des écarts** | **119** |
| **Fichiers avec au moins un écart** | 52 (+ 8 écarts transversaux) |
| **Vues conformes** | 38 |
| **Plan de correction** | 12 chantiers ordonnés ; les ordres 1→4 soldent 63 écarts (9 bloquants + 54 majeurs) |
