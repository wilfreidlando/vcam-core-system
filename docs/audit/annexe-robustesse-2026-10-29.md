# Annexe B — Audit de robustesse des formulaires et de la console (2026-10-29)

**Méthode** — audit statique en lecture seule du code applicatif et des vues. Deux passes : (1) extraction automatique, sur tout le dépôt, des constructions de commandes typées (`new *Command(`) et confrontation de chaque argument scalaire au type déclaré de la commande (103 commandes, 49 à paramètres `int`/`bool`/`float`) ; (2) confrontation de chaque `<form>` de la console et du portail à son contrôleur (règles de validation, casts, blocs d'erreurs, `old()`, attributs `required`/`min`/`max`/`step`, confirmations). Sémantique Laravel vérifiée dans le vendor plutôt que supposée : `validateInteger()` = `filter_var($v, FILTER_VALIDATE_INT) !== false` et `validateBoolean()` = `in_array($v, [true,false,0,1,'0','1'], true)` — **une règle `integer`/`boolean` n casts pas la valeur**. Le point de départ est le correctif du 2026-10-29 (commit `d667a7a`) : la recette navigateur avait trouvé que les formulaires de la console envoyaient des chaînes à des commandes typées `int` et plantaient en 500, alors que les tests automatisés passaient (les tests envoyaient des entiers). La question auditée est : **le même défaut de classe existe-t-il encore ailleurs ?**

**Périmètre** — `app/Http/Controllers/Admin/**` (34 contrôleurs), `resources/views/admin/**` (57 vues), `resources/views/layouts/admin.blade.php`, `packages/portal/**` (4 contrôleurs, 2 `FormRequest`, 20 vues), `packages/*/src/Presentation/Http/Requests/**` (15 `FormRequest`), `packages/*/src/Presentation/Http/Controllers/**`, `routes/admin.php`, `bootstrap/app.php`, `packages/*/src/Contracts/*Command.php`. Hors périmètre : le simulateur de paiement (`packages/payment/resources/views/simulator-checkout.blade.php`, page de staging) traité en fin de registre.

**Verdict** — **35 défauts : 6 bloquants, 21 majeurs, 8 mineurs.** Le correctif du 2026-10-29 a bien curé la console : **plus aucun `int`/`bool` non casté côté console**. Mais (a) **la même famille de défaut est intacte et à l'identique sur l'API publique `/v1`** — 15 commandes typées alimentées par des `FormRequest` dont les valeurs ne sont pas converties → `TypeError` → **500** ; (b) un **défaut de classe plus large que le typage** s'est installé dans la console : **20 formulaires POST répartis sur 10 vues n'affichent aucune erreur** (le layout `admin.blade.php` ne rend ni `$errors` ni `session('error')` pour ces cas) — l'opérateur est redirigé sans rien lire ; (c) la console n'a **ni CSP ni `[x-cloak]`** et dépend de deux CDN, ce qui neutralise ses propres confirmations.

Les chemins sont relatifs à la racine du dépôt. Le format `chemin:ligne` désigne le fichier et la ligne.

---

## 1. Registre des 35 défauts complet

| # | Fichier:ligne | Type de défaut | Gravité | Scénario utilisateur qui casse | Correction proposée |
|---|---|---|---|---|---|
| 1 | `app/Http/Controllers/Admin/BillingPlansController.php:102-108` + `resources/views/admin/billing/plans/form.blade.php:7` | Erreur non affichée | **bloquant** | L'opérateur crée un plan : un `code` refusé (regex `^[a-z0-9][a-z0-9_-]*$`, `max:64`) lève une `ValidationException` → `redirect back` → la vue n'a **ni `$errors` ni `@error`** → retour sur la page, champ pré-rempli, **aucun message**. Le formulaire « a l'air de ne rien faire ». | Ajouter un bloc `@if ($errors->any())` (identique à `ledger-adjustments/create.blade.php:21-27`) dans les 10 vues concernées — ou un composant `<x-admin.errors/>` rendu par le layout |
| 2 | `app/Http/Controllers/Admin/BillingPlansController.php:285-301` (`features()`) + `resources/views/admin/billing/plans/form.blade.php:114-116` | Erreur non affichée | **bloquant** | Le textarea « Fonctionnalités (JSON) » contient `{api_access: true}` (guillemet manquant) → `json_decode` échoue → `ValidationException::withMessages(['features' => …])` → **message perdu**, la version n'est jamais créée. C'est la seule règle serveur que le navigateur ne peut pas attraper. | Idem ligne 1 ; afficher l'erreur au-dessus du textarea |
| 3 | `app/Http/Controllers/Admin/BillingSubscriptionsController.php:207-243` + `resources/views/admin/billing/subscriptions/show.blade.php:120` | Erreur non affichée | **bloquant** | « Changer de plan » avec un `plan_code` inexistant → `PlanNotFound` → `refused()` → `back()->withErrors(['resource' => 'Ressource introuvable.'])` → la vue `subscriptions/show.blade.php` **n'a aucun bloc d'erreurs** → l'opérateur voit un rechargement de page et **aucun message**. Idem « Déclarer un règlement » (`:142`, `seq` inexistant), « Annuler » (`:161`), « Suspendre » (`:79`), « Mois offert » (`:102`), « Reprendre » (`:88`). | Idem ligne 1 (6 formulaires sur cette page) |
| 4 | `app/Http/Controllers/Admin/ProjectCommerceController.php:127-138` + `resources/views/admin/billing/settings.blade.php:7` | Erreur non affichée | **bloquant** | « Réglages du commerce » : l'opérateur saisit `default_currency = "XA"` (2 lettres ; le `maxlength="3"` du navigateur laisse passer) → le serveur exige `size:3` → refus → **message perdu**. | Idem ligne 1 |
| 5 | `app/Http/Controllers/Admin/ProjectCommerceController.php:68-81` + `resources/views/admin/billing/portal.blade.php:16` | Erreur non affichée | **bloquant** | « Réglages du portail » : une valeur refusée par `InvalidPortalSettings` (ex. `direct_login_session` vide alors que la connexion directe est activée) → `back()->withErrors(['resource' => …])` → **message perdu**. | Idem ligne 1 |
| 6 | `app/Http/Controllers/Admin/BillingCustomersController.php:330-341` + `resources/views/admin/billing/customers/form.blade.php:7` | Erreur non affichée | **bloquant** | « Nouveau client » : `external_ref` avec un caractère interdit (`/^[A-Za-z0-9._:-]+$/`) — le champ n'a **aucun `pattern` HTML** → le navigateur laisse passer → le serveur refuse → **message perdu**. Le champ `email` mal saisi produit le même silence. | Idem ligne 1 + ajouter `pattern="[A-Za-z0-9._:-]+"` au champ |
| 7 | `app/Http/Controllers/Admin/BillingCustomersController.php:294-325` + `resources/views/admin/billing/customers/index.blade.php:67,73,81` | Erreur non affichée | majeur | « Effacer » un client déjà effacé, ou « Suspendre » un client en transition invalide → `InvalidCustomerTransition` → `refused()` → **message perdu**. | Idem ligne 1 |
| 8 | `app/Http/Controllers/Admin/CustomerOverviewController.php:93-121` + `resources/views/admin/billing/customers/show.blade.php:64` | Erreur non affichée | majeur | « Créer l'abonnement » : un `plan_code` en majuscules passe le navigateur (aucun `pattern`) et échoue côté serveur (`^[a-z0-9][a-z0-9_-]{0,63}$`) → **message perdu**. | Idem ligne 1 + `pattern` sur `plan_code` |
| 9 | `app/Http/Controllers/Admin/ProjectExportsController.php:89-92` + `resources/views/admin/projects/exports.blade.php:41` | Erreur non affichée | majeur | « Télécharger » un export expiré (> 7 j) ou en échec → `redirect()->away(…)->withErrors(['resource' => 'Cet export n'a plus de fichier à télécharger.'])` → **le message n'est jamais affiché** (le layout ne rend que `session('status')`/`session('error')`, pas `$errors`). | Idem ligne 1 |
| 10 | `app/Http/Controllers/Admin/ReconciliationController.php:147-187` + `resources/views/admin/payments/show.blade.php:110` | Erreur non affichée | majeur | « Réconcilier » ce paiement depuis sa fiche : `PaymentNotFound` ou échec provider → `back()->withErrors(['payment' => …])` → **message perdu**. | Idem ligne 1 |
| 11 | `packages/billing/src/Presentation/Http/Controllers/CreatePlanVersionController.php:69,72,73,74,75` | Typage strict — chaîne → `int`/`bool` | majeur | `POST /v1/plans/{code}/versions` avec `{"price_minor":"5000"}` (chaîne JSON) **ou** corps `application/x-www-form-urlencoded` → la règle `integer` de Laravel est `filter_var($v, FILTER_VALIDATE_INT) !== false` : **"5000" passe** ; `validated()` rend la chaîne ; `CreatePlanVersionCommand.priceMinor` est `int` et le fichier a `declare(strict_types=1)` → **`TypeError` → 500** au lieu de 422. Même chemin pour `interval_count`, `trial_days`, `grace_days` (int) et `grace_access` (bool : `'1'`/`'0'` passent la règle `boolean`). | `(int) $data['price_minor']`, `(int) $data['interval_count']`, `(int) ($data['trial_days'] ?? 0)`, `(int) ($data['grace_days'] ?? 0)`, `filter_var($data['grace_access'] ?? true, FILTER_VALIDATE_BOOLEAN)` — ou, mieux, un `prepareForValidation()` qui caste dans le `FormRequest` (cf. § 3, point 2) |
| 12 | `packages/billing/src/Presentation/Http/Controllers/CreatePlanController.php:64` | Typage strict — chaîne → `int` | majeur | `POST /v1/plans` avec `{"sort_order":"10"}` → `integer` passe → `CreatePlanCommand.sortOrder` est `int` → **TypeError → 500**. | `(int) ($data['sort_order'] ?? 0)` |
| 13 | `packages/billing/src/Presentation/Http/Controllers/CreateSubscriptionController.php:82` | Typage strict — chaîne → `?int` | majeur | `POST /v1/subscriptions` avec `{"trial_days":"14"}` → `CreateSubscriptionCommand.trialDays` est `?int` → **TypeError → 500**. Le client ne peut pas distinguer « essai ignoré » d'« erreur serveur ». | `(int) $data['trial_days']` quand non null |
| 14 | `packages/billing/src/Presentation/Http/Controllers/CancelSubscriptionController.php:61` | Typage strict — chaîne → `bool` | majeur | `POST /v1/subscriptions/{id}/cancel` avec `{"at_period_end":"0"}` → la règle `boolean` accepte `'0'` → `CancelSubscriptionCommand.atPeriodEnd` est `bool` → **TypeError → 500**. Pire : une annulation **immédiate** demandée par `"0"` part en 500 au lieu d'annuler. | `filter_var($data['at_period_end'] ?? true, FILTER_VALIDATE_BOOLEAN)` |
| 15 | `packages/billing/src/Presentation/Http/Controllers/CreateInvoiceController.php:59,60,61,72` | Typage strict — chaîne → `int`/`?int` | majeur | `POST /v1/invoices` avec `{"lines":[{"quantity":"2","unit_amount_minor":"50000"}]}` → `InvoiceLineInput.quantity`/`unitAmountMinor` sont `int` → **TypeError → 500**. Idem `tax_rate_bps` (ligne de facture et en-tête). | Casts explicites sur les 4 champs |
| 16 | `packages/billing/src/Presentation/Http/Controllers/ConsumeUsageController.php:57` | Typage strict — chaîne → `int` | majeur | `POST /v1/usage` avec `{"quantity":"5"}` → `ConsumeUsageCommand.quantity` est `int` → **500**. Le quota n'est pas décrémenté et l'appelant ne sait pas pourquoi. | `(int) $data['quantity']` |
| 17 | `packages/payment/src/Presentation/Http/Controllers/AbstractStartPaymentController.php:70` | Typage strict — chaîne → `int` | majeur | `POST /v1/payments/paylink` avec `{"amount":"5000"}` → `StartPaymentCommand.amount` est `int` → **500 sur une route d'argent** (avec `Idempotency-Key` : la clé est consommée, un rejeu repart en 500). | `(int) $data['amount']` |
| 18 | `packages/wallet/src/Presentation/Http/Controllers/RecordWithdrawalController.php:52` | Typage strict — chaîne → `int` | majeur | `POST /v1/wallets/withdrawals` avec `{"amount":"1000"}` → `RecordWithdrawalCommand.amountMinor` est `int` → **500**. | `(int) $data['amount']` |
| 19 | `packages/wallet/src/Presentation/Http/Controllers/TransferFundsController.php:63` | Typage strict — chaîne → `int` | majeur | `POST /v1/wallets/transfers` avec `{"legs":[{"amount":"100"}]}` → `TransferLeg.amount` est `int` → **500**. | `(int) $leg['amount']` |
| 20 | `packages/portal/src/Presentation/Http/Controllers/PortalSettingsController.php:63,64,66` | Typage strict — chaîne → `bool` | majeur | `PUT /v1/portal/settings` avec `{"allow_self_cancel":"1"}` → la règle `boolean` accepte `'1'` → `PortalSettingsData.allowSelfCancel` est `bool` → **500**. | `filter_var(…, FILTER_VALIDATE_BOOLEAN)` sur les 3 champs |
| 21 | `packages/portal/src/Presentation/Http/Controllers/OpenPortalSessionController.php:68` | Perte silencieuse de donnée | majeur | `POST /v1/portal/sessions` avec `{"trial_days":"14"}` → `is_int("14")` est **faux** → `trialDays` devient **null** → l'abonnement est créé **sans les 14 jours d'essai demandés**, sans erreur, sans avertissement. Le client croit avoir obtenu un essai. | `(int) $data['trial_days']` si `is_numeric`, sinon `null` — et ne pas perdre la valeur pour une chaîne numérique |
| 22 | `resources/views/admin/billing/customers/form.blade.php:21,33,71` | Valeur par défaut non appliquée (perte de saisie) | majeur | En **mode édition**, `value="{{ $customer?->displayName ?? old('display_name') }}"` : le modèle gagne **toujours** → `old('display_name')` est ignoré. L'opérateur corrige un contact invalide, la validation échoue, il renvoie : **le nom qu'il venait de saisir a disparu et le nom stocké est revenu**. Idem `locale` (ligne 71). `external_ref` (ligne 21) est en `readonly` en édition, sans impact. | `value="{{ old('display_name', $customer?->displayName) }}"` (l'argument `old()` d'abord), idem `locale` |
| 23 | `resources/views/admin/billing/plans/form.blade.php:66` | Mauvaise clé `old()` | majeur | Le champ du formulaire de version est `name="plan_code"` mais sa valeur relue est `old('code', '')` — la clé du **premier** formulaire. Sur un échec de validation de la version, le `plan_code` saisi est **perdu** et le champ repart vide (alors que `old('code')` contient le code du plan créé, pas celui de la version). | `value="{{ old('plan_code', '') }}"` |
| 24 | `resources/views/admin/payments/show.blade.php:66` | XSS (URL `javascript:`) | majeur | `href="{{ $payment->paymentUrl }}"` : l'URL vient de la **réponse du provider** (`packages/payment/src/Infrastructure/Provider/MyCoolPay/MyCoolPayAdapter.php:258`) et n'est **pas** validée — contrairement au portail, qui exige `https://` (`packages/portal/src/Application/UseCase/PortalWorkspace.php:474`). Un provider hostile ou compromis renvoie `javascript:…` ; le clic de l'opérateur exécute du JS **dans la session console**. La console n'a **aucune CSP** (le portail, si). | Valider le schéma avant rendu (`str_starts_with($url, 'https://')`) + ajouter une CSP `script-src 'self'` à `resources/views/layouts/admin.blade.php` |
| 25 | `resources/views/layouts/admin.blade.php:15,18` | Dépendance CDN : style et confirmations | majeur | La console charge Tailwind et Alpine depuis `cdn.tailwindcss.com` et `unpkg.com`. Sur une installation air-gap ou à réseau filtré (stack Docker locale), **Alpine ne démarre jamais** : les 8 modales de confirmation (`x-show` + `x-cloak`) restent **visibles en permanence**, et leur bouton « Confirmer » est un `<form method="POST">` vivant → la confirmation en deux temps devient **un clic**. | Servir Tailwind compilé et Alpine en local (`public/assets/`), comme le portail (`public/assets/portal/portal.css`) |
| 26 | (absence) `resources/views/layouts/admin.blade.php` | Règle CSS `[x-cloak]` non définie | majeur | Aucun CSS `[x-cloak]{display:none}` dans le dépôt. Alpine ne l'injecte pas : les 8 overlays `fixed inset-0 z-50 bg-slate-900/50` s'affichent **avant** le démarrage d'Alpine (script `defer` depuis un CDN) — flash d'écran sur chaque page qui en contient un. | Ajouter `[x-cloak]{display:none !important}` dans le `<style>` du layout (ligne 20-37) |
| 27 | `resources/views/admin/billing/invoices/show.blade.php:247` (Émettre), `:265` (Annuler), `:282` (Irrécouvrable) + `resources/views/admin/billing/subscriptions/show.blade.php:161` (Annuler) | Action destructive sans confirmation | majeur | « Émettre la facture » est **irréversible** : numérotation sans trou (`FACT-2026-000042`), inscription de la créance au grand livre, **document figé**. Un clic malencontreux sur un montant erroné fige une créance fausse ; la seule voie est l'annulation, elle-même irréversible et auditée. « Annuler la facture » et « Annuler l'abonnement » n'ont aucun `onsubmit="return confirm(…)"`. | Reprendre le modèle déjà présent : `onsubmit="return confirm('Émettre et figer cette facture ?')"` (comme `resources/views/admin/billing/customers/index.blade.php:83`), ou une modale Alpine comme `resources/views/admin/operators/index.blade.php:84-107` |
| 28 | `resources/views/admin/billing/plans/index.blade.php:34` (Archiver), `:71` (Publier) ; `resources/views/admin/billing/customers/index.blade.php:67,73` (Suspendre/Réactiver) | Action destructive sans confirmation | mineur | « Publier » fige les conditions (prix, période, essai) ; un clic sur la mauvaise ligne publie un tarif erroné. « Archiver » retire l'offre des souscriptions nouvelles. | Confirmation sur « Publier » et « Archiver » |
| 29 | `app/Http/Controllers/Admin/BillingPlansController.php:171` vs `resources/views/admin/billing/plans/form.blade.php:59-126` | Champ validé mais absent du formulaire | mineur | Le contrôleur valide `'grace_access' => ['nullable','boolean']` et lit `$request->has('grace_access') ? $request->boolean('grace_access') : true`, mais **le formulaire n'a aucune case `grace_access`** → la valeur est toujours `true` : l'opérateur **ne peut jamais désactiver l'accès pendant la grâce** depuis la console. | Ajouter la case à cocher au formulaire de version, ou retirer la règle si le choix n'est pas exposé |
| 30 | `resources/views/admin/billing/plans/form.blade.php:85-91` et `:21-25` | `old()` absent sur un `<select>` | mineur | `interval_unit` porte un `selected` **en dur** sur « mois » (au lieu de `@selected(old('interval_unit') === 'month')`) et `visibility` n'a aucun `old()` → le choix est perdu après une erreur de validation. | `@selected(old('interval_unit', 'month') === 'month')` / `@selected(old('visibility', 'public') === 'public')` |
| 31 | `resources/views/admin/billing/subscriptions/show.blade.php:145` | Valeur par défaut non appliquée | mineur | Le champ `seq` (« n° de période ») est vide alors que le tableau au-dessus de la même page affiche déjà le `seq` de chaque période → l'opérateur recopie à la main un numéro qu'il a sous les yeux. | `value="{{ old('seq', $periods[0]->seq ?? '') }}"` ou un `<select>` alimenté par `$periods` |
| 32 | `resources/views/admin/billing/invoices/form.blade.php:74` vs `app/Http/Controllers/Admin/BillingInvoicesController.php:165` | Contrainte navigateur ≠ serveur | mineur | `lines[N][quantity]` a `min="1"` mais **pas** `required` côté navigateur, alors que le serveur impose `lines.*.quantity` `required` → l'opérateur peut vider le champ (le navigateur laisse passer), la ligne est conservée (elle a un prix), et le serveur refuse le lot entier → **message perdu** (ligne 1). | `required` sur le champ quantité, ou `nullable` + valeur par défaut côté serveur |
| 33 | `packages/portal/resources/views/login.blade.php:13`, `packages/portal/resources/views/login-verify.blade.php:13` | Absence de CSRF (documentée) | mineur | Les deux formulaires de connexion directe n'ont pas de `_csrf`. C'est un **choix assumé et documenté** (ADR-0075 §2, `packages/portal/src/Presentation/Http/Controllers/DirectLoginController.php:16-22`) : la protection est le code à 6 chiffres et les budgets (30/min/IP, 5 essais, plafond horaire/projet). Reste que `POST /portal/{id}/login` **envoie un WhatsApp** à un numéro arbitraire : une page tierce peut faire poster le formulaire depuis le navigateur d'une victime (≈30 envois/min/IP, plafonnés par projet). La fonctionnalité est **désactivée par défaut**. | Accepter le risque (documenté) ou ajouter un jeton à usage unique sur la page de login |
| 34 | `resources/views/admin/billing/customers/index.blade.php:95`, `resources/views/admin/billing/plans/index.blade.php:87` | Pagination sans « précédent » | mineur | La pagination par curseur n'offre que « Page suivante → ». Dès que l'opérateur avance, il ne peut pas revenir en arrière sans recharger l'URL d'origine. | Conserver le curseur précédent et le rendre lisible |
| 35 | `packages/payment/resources/views/simulator-checkout.blade.php:41-44` | Bouton sans attribut `type` | mineur | 4 `<button>` sans `type` dans un formulaire → le défaut HTML est `submit`. Ici les libellés (« Payer (succès) », « Échec du paiement », « Annuler », « Laisser expirer ») impliquent des actions distinctes : sans `type`, **tous soumettent le formulaire**. | `type="submit"` / `type="button"` explicites |

---

## 2. Les 3 points systémiques

Ce ne sont pas trois défauts du registre : ce sont les trois corrections qui **règnent le plus de cas** et qui, prises une par une, ferment le plus grand nombre de lignes du tableau.

### Point 1 — Un bloc d'erreurs global dans le layout (ferme les lignes 1 à 10)

`resources/views/layouts/admin.blade.php:310-325` rend `session('status')` et `session('error')` — **mais pas `$errors`**. Or `bootstrap/app.php:155-160` fait correctement retomber les `ValidationException` sur le `redirect back with $errors` de Laravel, et `app/Http/Controllers/Admin/Concerns/HandlesBillingRefusals.php:42` fait `back()->withErrors([...])`. **Le flash est donc produit, puis jeté.** 20 formulaires POST répartis sur 10 vues (`billing/plans/form`, `billing/plans/index`, `billing/settings`, `billing/portal`, `billing/subscriptions/show`, `billing/customers/form`, `billing/customers/index`, `billing/customers/show`, `payments/show`, `projects/exports`) sont dans ce cas.

```blade
{{-- dans resources/views/layouts/admin.blade.php, avant @yield('content') --}}
@if ($errors->any())
    <div class="rounded-md bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800" role="alert">
        @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
    </div>
@endif
```

**Un seul changement ferme les défauts 1 à 10** et la moitié du 32. C'est le même geste que celui déjà appliqué au middleware dans `bootstrap/app.php` : la correction a été faite côté rendu d'exception, pas côté affichage.

### Point 2 — Un cast unique, dans le `FormRequest`, pour toute la famille `int`/`bool` (ferme les lignes 11 à 21)

Le correctif du 2026-10-29 a casté **site par site** dans `app/Http/Controllers/Admin/*` — 11 casts. La même règle doit s'appliquer à l'API, mais **une seule fois** : dans le `FormRequest`, via `prepareForValidation()` (ou un trait partagé `CastsScalars`), en convertissant les valeurs selon les règles `integer`/`numeric`/`boolean` **avant** `validated()`. Les 15 sites des lignes 11 à 21 disparaissent d'un geste, et le risque de régression disparaît avec.

Le modèle existe déjà dans le dépôt et est à généraliser : `packages/webhook/src/Presentation/Http/Requests/RotateWebhookSecretRequest.php:33-38` fait `is_numeric($value) ? (int) $value : …`. Le contre-exemple à ne pas copier est `packages/portal/src/Presentation/Http/Controllers/OpenPortalSessionController.php:68` (`is_int(...) ? … : null`), qui **perd** la donnée au lieu de la convertir (ligne 21).

Règle à écrire dans `CLAUDE.md` : *« une règle de validation `integer`/`numeric`/`boolean` ne garantit pas le type — la valeur doit être convertie avant d'atteindre une commande typée »*. Cette règle doit être vérifiée par un test d'architecture (grep des casts dans les `Presentation/Http/Controllers/**`), comme les autres garde-fous.

### Point 3 — Un composant de confirmation unique pour les actes irréversibles (ferme les lignes 27 et 28)

7 actions destructives n'ont aucune confirmation alors que 3 vues en ont déjà une : `resources/views/admin/billing/customers/index.blade.php:83` (en `onsubmit`), `resources/views/admin/operators/index.blade.php:84-107` et `resources/views/admin/organization-members/index.blade.php:87` (en modale Alpine). Extraire un composant Blade `<x-admin.confirm action="…" message="…">` et l'imposer sur **émettre / annuler / irrecouvrable / archiver / publier / résilier** ferme les lignes 27 et 28 d'un geste — et rend la règle **vérifiable par un test d'architecture** (aucune route mutante destructrice sans `<x-admin.confirm>`), ce qui est le seul moyen d'empêcher le retour de ce défaut de classe.

---

## 3. Décompte par package et par surface

### 3.1 Par package / surface

| Package / surface | Fichiers | Typage strict | Erreurs non affichées | Valeurs par défaut | Destructif sans confirm. | XSS / CSP / CSRF | Total |
|---|---|---|---|---|---|---|---|
| **`admin`** — `app/Http/Controllers/Admin/**` + `resources/views/admin/**` | 34 ctrl / 57 vues | 0 bloquant (2 lacunes mineures : 29, 31) | **10** (1→10) | 5 (22, 23, 29, 30, 31) | 2 (27, 28) | 3 (24, 25, 26) | **22** |
| **`billing`** — API `/v1` | 6 req / 12 ctrl | **6** (11→16) | — | — | — | — | **6** |
| **`portal`** | 2 req / 4 ctrl / 20 vues | 2 (20, 21) | — | — | — | 1 CSRF documenté (33) | **3** |
| **`payment`** | 3 req / 4 ctrl + 1 vue staging | 1 (17) | — | — | — | 1 bouton sans `type` (35) | **2** |
| **`wallet`** | 3 req / 4 ctrl | 2 (18, 19) | — | — | — | — | **2** |
| **`identity`** | 3 req / 5 ctrl | 0 | — | — | — | — | **0** |
| **`ledger`**, **`notification`**, **`webhook`**, **`export`** | — | 0 | — | — | — | — | **0** |
| **Total** | | **11** | **10** | **5** | **2** | **5** | **35** |

### 3.2 Par famille de défaut

| Famille (cahier des charges) | Bloquants | Majeurs | Mineurs | Total | Lignes du registre |
|---|---|---|---|---|---|
| **1 — Typage strict des commandes** | 0 | 11 | 0 | **11** | 11 → 21 |
| **2 — Champs conditionnels / `step` / `min`** | 0 | 0 | 1 | **1** | 32 |
| **3 — Valeurs par défaut non appliquées** | 0 | 2 | 3 | **5** | 22, 23, 29, 30, 31 |
| **4 — Erreurs non affichées** | **6** | 4 | 0 | **10** | 1 → 10 |
| **5 — Actions destructrices sans confirmation / boutons sans `type`** | 0 | 1 | 2 | **3** | 27, 28, 35 |
| **6 — Pagination et tri** | 0 | 0 | 1 | **1** | 34 |
| **7 — Upload de fichier (taille, MIME réel, nom de fichier)** | 0 | 0 | 0 | **0** | — (surface inexistante, cf. § 4) |
| **8 — CSRF et `method="DELETE"` / `@method`** | 0 | 0 | 1 | **1** | 33 |
| **9 — XSS (`{!! !!}`, URL de schéma, CSP)** | 0 | 3 | 0 | **3** | 24, 25, 26 |
| **Total** | **6** | **21** | **8** | **35** | |

---

## 4. Ce qui a été vérifié et est propre

Cette section est normative : elle empêche de casser l'existant lors de la correction.

### 4.1 — Console web, typage strict : cured

Les 11 champs `int`/`bool`/`float` de la console ont **tous** un cast explicite. Il n'y a plus aucun 500 de ce type côté console.

| Contrôleur | Ligne | Cast |
|---|---|---|
| `app/Http/Controllers/Admin/BillingPlansController.php` | 117 | `(int) ($data['sort_order'] ?? 0)` |
| idem | 180, 183, 184, 185 | `(int) $data['price_minor']`, `(int) $data['interval_count']`, `(int) ($data['trial_days'] ?? 0)`, `(int) ($data['grace_days'] ?? 0)` |
| idem | 186 | `$request->has('grace_access') ? $request->boolean('grace_access') : true` |
| `app/Http/Controllers/Admin/BillingInvoicesController.php` | 173, 174, 175 | `(int) $line['quantity']`, `(int) $line['unit_amount_minor']`, `(int) $line['tax_rate_bps']` |
| idem | 187 | `(int) $data['tax_rate_bps']` |
| `app/Http/Controllers/Admin/BillingInvoiceReceiptsController.php` | 109 | `(int) $data['amount_minor']` |
| `app/Http/Controllers/Admin/BillingSubscriptionsController.php` | 262 | `(int) $data['days']` |
| idem | 296 | `(int) $data['seq']` |
| `app/Http/Controllers/Admin/CustomerOverviewController.php` | 113 | `(int) $data['trial_days']` |
| `app/Http/Controllers/Admin/ProjectCommerceController.php` | 147, 148, 151, 152 | `(int)`, `(int)`, `(int) round(((float) …) * 100)`, `(int)` |
| idem | 154, 155 | `$request->boolean('refund_failed_usage')`, `$request->boolean('require_customer_ref')` |
| `app/Http/Controllers/Admin/BillingCustomersController.php` | 174 | `$request->integer('version')` |
| `app/Http/Controllers/Admin/LedgerAdjustmentsController.php` | 258 | `(int) $data['amount_minor']` |
| `app/Http/Controllers/Admin/ReconciliationController.php` | 90 | `(int) $data['limit_override']` |
| `app/Http/Controllers/Admin/ApiKeysController.php` | 121 | `(int) $data['grace_days']` |

### 4.2 — Champs conditionnels, `step` et `min` : cohérents partout

Les 18 `<input type="number">` de la console et du portail ont été confrontés **une à une** aux règles serveur. `min`/`max`/`required` sont **cohérents partout** ; aucun `step` ne bloque une saisie légitime (le seul `step="0.01"` — `settings.blade.php:30` — correspond à une règle `numeric`, pas `integer`).

Le correctif « facture impossible avec moins de 3 lignes » est **correct** : `app/Http/Controllers/Admin/BillingInvoicesController.php:151-155` filtre les lignes vides sur « libellé **ou** montant », `quantity` soumet toujours sa valeur par défaut `1`, et `filled("0")` est vrai (Laravel `blank("0")` renvoie `false`) — une ligne à 0 est donc conservée. **Aucun autre motif « N lignes obligatoires » dans le dépôt** (`'array','min:2'` / `'min:3'` n'apparaît nulle part).

### 4.3 — Pagination et tri : conformes

- Tous les `page` / `per_page` / `limit` sont bornés : `max(1, (int) …)` puis `min(100, max(1, …))` (`NotificationsController:51-52`, `OperatorsController:61-62`, `OrganizationsController:56-57`, `PaymentsController:81-82`, `ProjectsController:74-75`, `WalletsController:61-62,108-109`, `WebhookDeliveriesController:94-95`, `BillingCustomersController:364-367`), ou par `ctype_digit` (`LedgerAdjustmentsController:122-123`, `MoneyExceptionsController:57-58`). **Aucun paramètre non borné → ni 500 ni charge.**
- Les curseurs sont des chaînes opaques, jamais réinterprétés.
- **Aucun paramètre de tri utilisateur** dans la console ni dans le portail : l'ordre est fixé côté serveur (`usort` dans `PortalWorkspace:127,341,353,581`, `PaymentsController:258`, `PortfolioController:71`, `ProjectLedgerController:51`, `ProjectIndicatorsController:60`). **Aucune injection de tri possible.**

### 4.4 — Upload de fichier : surface inexistante

Zéro `type="file"` dans la console et dans le portail. Le logo du portail est une **URL** (`logo_url`), validée côté domaine (`packages/portal/src/Domain/Model/PortalSettings.php:85` : `isHttpsUrl` **ou** `mailto:` — un `javascript:` est refusé). Les pièces de notification sont une `media_url` téléchargée par le provider, protégée par `SafeOutboundUrlRule`. **Aucun défaut de type MIME, de taille ou de nom de fichier à corriger : il n'y a pas de surface d'upload.**

### 4.5 — CSRF et méthodes HTTP : conformes

- `@csrf` sur **tous** les formulaires POST de la console (vérifié sur les 60 formulaires).
- `@method('PUT')` sur les 5 formulaires PUT : `resources/views/admin/organizations/edit.blade.php:18`, `resources/views/admin/projects/edit.blade.php:17`, `resources/views/admin/operators/edit.blade.php:31`, `resources/views/admin/organization-members/index.blade.php:61`, `resources/views/admin/webhook-endpoints/create.blade.php:151`.
- Aucune route `DELETE` : toutes les actions destructrices passent en POST, par choix de conception — **ce n'est pas un défaut**.
- Le portail valide son `_csrf` par `hash_equals` sur **tous** les POST authentifiés (`packages/portal/src/Presentation/Http/Middleware/AuthenticatePortal.php:35`), et `packages/portal/src/Presentation/Http/Middleware/PortalSecurityHeaders.php:21` impose `default-src 'none'; style-src 'self'; img-src 'self' https: data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'`.
- Les 10 formulaires GET de la console (filtres, recherche) n'ont pas de `@csrf` — **correct**, la méthode par défaut d'un `<form>` sans `method` étant GET.

### 4.6 — XSS Blade : aucun `{{ }}` dangereux

- **Un seul** `{!! !!}` dans tout le dépôt : `resources/views/admin/auth/mfa-enroll.blade.php:45` (`{!! $qrSvg !!}`), SVG généré par `chillerlan/php-qrcode` à partir de l'URI `otpauth://` du serveur — **jamais** d'une donnée utilisateur. **Ne pas le modifier.**
- Nom de projet, libellé de plan, contenu de notification, référence de tiers, nom de client, contenu du tiers : tous rendus en `{{ }}`.
- `style="background: {{ … }}"` (`resources/views/admin/billing/portal.blade.php:30`) est gardé par une regex ; `style="width: {{ $row->percent }}%"` (`resources/views/admin/billing/usage.blade.php:34`) vient d'un calcul serveur.
- `onsubmit="return confirm(@js($confirmMessage))"` (`resources/views/admin/channel-sessions/index.blade.php:60`) échappe correctement via `@js()`.
- `href="{{ $grafanaUrl }}/…"` (`resources/views/admin/payments/partials/journey.blade.php:39`) vient de la configuration, pas d'une donnée utilisateur.

### 4.7 — Divers

- `ConvertEmptyStringsToNull` est un middleware global par défaut (`Illuminate/Foundation/Configuration/Middleware.php:462`) → un champ numérique laissé vide devient `null`, pas `""` : les règles `nullable` se comportent comme prévu partout.
- `bootstrap/app.php:155-160` fait correctement retomber les `ValidationException` sur le `redirect back with $errors` de Laravel pour les requêtes HTML — **la chaîne d'exception est saine ; c'est l'affichage qui manque** (cf. § 2, point 1).
- Les 20 vues d'authentification de la console (`admin/auth/*`) affichent bien leurs erreurs : `@error('email')` (`login.blade.php:38`), `@error('code')` (`mfa-verify.blade.php:53`, `mfa-enroll.blade.php:86`), `@error('token')` et `@error('password')` (`invitation.blade.php:34,49`) — et les contrôleurs utilisent les bonnes clés (`AuthController.php:109,154,208,240,310`, `InvitationController.php:74`). **Ne pas y toucher.**
- Le portail n'affiche pas d'erreurs via `$errors` mais via une variable `$error` injectée par le contrôleur (`PortalPagesController.php:115,153,166,180,193,214`) — cohérent, **ne pas y toucher**.
- Les 8 modales Alpine de confirmation sont **correctement scoping** (`x-data` sur le `<tr>` ou le `<li>` parent : `operators/index.blade.php:43`, `organization-members/index.blade.php:53`, `reconciliation/new.blade.php:67`, `webhook-deliveries/dead.blade.php:71`, `provider-applications/create.blade.php:48`, `api-keys/create.blade.php:63`, `webhook-endpoints/create.blade.php:74`) — seul le `[x-cloak]` manque (ligne 26).

---

## 5. Deux réserves de méthode

1. **Le portail et la console n'ont pas été testés dans un vrai navigateur pendant cet audit** (audit statique uniquement ; `php` n'est pas disponible dans l'environnement d'exécution, donc aucun test n'a pu être exécuté). Les scénarios d'erreur (lignes 1 à 10) sont **déduits** de la chaîne Laravel + Blade vérifiée (`bootstrap/app.php:155-160`, `Concerns/HandlesBillingRefusals.php:42`, `resources/views/layouts/admin.blade.php:310-325`) ; ils sont à rejouer une fois dans un vrai navigateur avant livraison, comme l'a fait la recette du 2026-10-29. Les lignes 11 à 21 (API) sont, elles, déduites d'une sémantique Laravel vérifiée **dans le vendor** (`ValidatesAttributes::validateInteger()` / `validateBoolean()`) et de `declare(strict_types=1)` dans les fichiers appelants — la chaîne est donc vérifiable sans exécution.

2. **Les 15 défauts API (lignes 11 à 20) sont des 500, pas des 422**, et ils supposent un client qui envoie un nombre **en chaîne** (sérialisation naïve d'un SDK, ou corps `application/x-www-form-urlencoded` — Laravel lit alors `$this->request`, cf. `Illuminate/Http/Request.php:486-493`). La suite automatisée ne couvre pas ce cas : `tests/Integration/SaasLifecycleFlowTest.php` n'utilise `postJson` qu'avec de vrais entiers — c'est exactement le « les tests envoyaient des entiers » du 2026-10-29, **reconduit côté API**. Un test de non-régression qui poste `{"price_minor":"5000"}` et attend **422** suffirait à fermer la famille entière ; il manque aujourd'hui.

---

## Cartouche de signature

| Champ | Valeur |
|---|---|
| **Date de l'audit** | 2026-10-29 |
| **Auteur** | Audit de robustesse des formulaires et de la console (agent Claude — rôle Senior Code Reviewer), sous la supervision du coordinateur `vcam-core` |
| **Référentiel** | ADR-0071 (plan/version), ADR-0072 (abonnement), ADR-0073 (facture), ADR-0075 §2 / ADR-0100 (connexion directe), ADR-0083 (données personnelles), ADR-0092 §4 (refus de la console), ADR-0099 (périmètre) ; garde-fous G3b/G3c/G5/G16 ; sémantique Laravel 12/13 vérifiée dans `vendor/laravel/framework` |
| **Périmètre audité** | `app/Http/Controllers/Admin/**` (34 ctrl), `resources/views/admin/**` (57 vues), `resources/views/layouts/admin.blade.php`, `packages/portal/**` (4 ctrl, 2 req, 20 vues), `packages/*/src/Presentation/Http/Requests/**` (15 req), `packages/*/src/Presentation/Http/Controllers/**`, `packages/*/src/Contracts/*Command.php`, `routes/admin.php`, `bootstrap/app.php` |
| **Point de départ** | Correctif du 2026-10-29 (commit `d667a7a`) : les formulaires de la console envoyaient des chaînes à des commandes typées `int` → 500 en navigateur, tests automatisés verts |
| **Mode** | Lecture seule — **aucun fichier du dépôt modifié par l'audit** ; aucune commande base de données ; aucun accès à l'Internet ; aucune valeur réelle ni secret reproduit (exemples factices) |
| **Défauts bloquants** | **6** |
| **Défauts majeurs** | **21** |
| **Défauts mineurs** | **8** |
| **Total des défauts** | **35** |
| **Répartition** | Console `admin` 22 · API `billing` 6 · `portal` 3 · `payment` 2 · `wallet` 2 · `identity`/`ledger`/`notification`/`webhook`/`export` 0 |
| **Plan de correction** | 3 chantiers systémiques : (1) bloc d'erreurs global dans le layout → ferme 10 défauts ; (2) cast unique dans les `FormRequest` → ferme 11 ; (3) composant de confirmation → ferme 2. Les 12 défauts restants (valeurs par défaut, `old()`, CSP, `[x-cloak]`, CDN) se traitent vue par vue. |
