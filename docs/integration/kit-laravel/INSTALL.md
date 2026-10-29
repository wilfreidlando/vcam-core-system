# Brancher votre SaaS au Core — 30 minutes

Vous avez codé votre SaaS (tenants, utilisateurs, métier). Le Core gère **tout le
commerce** : plans, abonnements, factures, **paiement** (Mobile Money, carte — vous
n'avez aucun prestataire à gérer), quotas, espace client hébergé. Vous écrivez
uniquement : *qui est le tenant de cette requête* et *quoi faire quand son
abonnement change*.

> Un **tenant** de votre SaaS est un **client payeur** (`customer`) du Core, désigné par
> la référence que vous choisissez (son id). Vos utilisateurs finaux restent chez vous :
> le Core ne les connaît pas (ADR-0084).

## Avant (une fois, côté plateforme — 10 min)

Un opérateur de la console Core fait ces trois gestes pour votre projet :

1. **Créer le projet** et émettre une clé avec le modèle **« Serveur complet »** →
   `pk_…` (montrée une seule fois).
2. **Publier un plan** (`POST /v1/plans`, `/versions`, `/publish`) : prix, durée,
   et vos quotas, par exemple
   `"features": {"whatsapp": {"type":"limit","meter":"whatsapp_messages","limit":500,"reset":"period","overage":"block","threshold_bps":8000}}`.
3. **Régler le portail** : `PUT /v1/portal/settings` avec votre nom, votre logo, votre
   couleur et `allowed_return_urls` (l'adresse de votre application). C'est tout :
   **aucun moyen de paiement à configurer**, le portail propose ceux que la plateforme
   sait traiter.

Enregistrez aussi votre récepteur d'événements (voir l'étape 4) et notez le `whsec_…`.

## Côté SaaS (20 min)

### 1. Copier le kit (2 min)

```
docs/integration/kit-laravel/app/Billing/*   →  app/Billing/
docs/integration/kit-laravel/config/core_billing.php → config/
```

`.env` :

```
CORE_BASE_URL=https://core.example.com
CORE_API_KEY=pk_…
CORE_WEBHOOK_SECRET=whsec_…
CORE_DEFAULT_PLAN=pro
CORE_TRIAL_DAYS=14
```

### 2. Dire qui est le tenant (2 min)

Dans `EnsureTenantSubscribed::tenantRef()` : une ligne qui renvoie la référence du
tenant de la requête (par exemple `$request->user()->tenant_id`). Même valeur partout.

### 3. Créer le tenant dans le Core à l'inscription (3 min)

Là où votre code crée un tenant :

```php
app(\App\Billing\CoreBilling::class)->provision((string) $tenant->id, [
    'name' => $tenant->name, 'email' => $tenant->email, 'phone' => $tenant->phone,
]);   // le tenant démarre sur votre plan par défaut, en essai gratuit
```

### 4. Recevoir les changements d'abonnement (5 min)

Collez les routes de `routes-snippet.php`, excluez `webhooks/core` du CSRF, puis
enregistrez l'endpoint (une fois) :

```bash
curl -s -X POST "$CORE_BASE_URL/v1/webhook-endpoints" \
  -H "Authorization: Bearer $CORE_API_KEY" -H "Content-Type: application/json" \
  --data '{"url":"https://votre-saas.example/webhooks/core","events":["subscription.activated","subscription.renewed","subscription.past_due","subscription.expired","subscription.canceled","invoice.paid","usage.threshold_reached","usage.limit_reached"]}'
# → "secret":"whsec_…"  (montré une seule fois → CORE_WEBHOOK_SECRET)
```

Puis éditez **`TenantAccess::apply()`** : c'est le *seul* code métier à écrire. Il reçoit
`active`, `warning` (paiement en retard, accès maintenu) ou `blocked`.

### 5. Protéger ce qui est payant (3 min)

```php
Route::middleware(['auth', 'subscribed'])->group(/* vos routes payantes */);
Route::middleware(['auth', 'subscribed:whatsapp'])->post('/messages', …);   // une fonctionnalité du plan
```

Compter un quota — refusé automatiquement au-delà du plan :

```php
try {
    app(CoreBilling::class)->consume((string) $tenant->id, 'students', key: "student:{$student->id}");
} catch (\App\Billing\QuotaExceeded $e) {
    return back()->withErrors("Limite de votre offre atteinte ({$e->used}/{$e->limit}).");
}
```

### 6. Le bouton « Gérer mon abonnement » (déjà fait)

`GET /billing` (du kit) ouvre le portail du Core : abonnement, **factures, paiement**,
utilisation. Votre utilisateur y arrive connecté — aucun mot de passe de plus — et
revient chez vous avec « Retour ».

## Vérifier (5 min)

| Je fais… | Je dois voir… |
|---|---|
| Créer un tenant | `GET /v1/customers/{id}/entitlements` → `active: true`, plan, quotas |
| Cliquer « Gérer mon abonnement » | le portail à votre couleur, la facture à payer |
| Payer (staging : simulateur, `POST /v1/payments/{id}/simulate`) | webhook `invoice.paid` → `TenantAccess::apply(…, 'active')` |
| Dépasser un quota | `QuotaExceeded` ; rien n'est compté |
| Laisser l'abonnement expirer | webhook `subscription.expired` → accès bloqué |

## Ce que le kit garantit pour vous

* **Fail-closed** : un tenant inconnu du Core n'a aucun droit.
* **Rejeu sans danger** : un événement reçu deux fois n'est traité qu'une fois ; un
  événement livré dans le désordre est ignoré s'il est plus ancien que le dernier.
* **Aucune perte** : si vous ratez un webhook, la lecture des droits (cache 60 s) vous
  remet dans le bon état.
* **Aucun secret d'utilisateur final** ne transite par le Core.
