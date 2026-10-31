# Brancher votre application au Core — 30 minutes

Le Core fait quatre métiers que votre application n'a pas à refaire. Vous prenez ce dont
vous avez besoin, le reste reste éteint :

| Profil | Le Core s'occupe de… | Vous écrivez |
|---|---|---|
| **A. Paiement et soldes** | encaisser (Mobile Money, carte — aucun prestataire à gérer), tenir les **soldes** de vos tiers (wallets), les transférer entre eux, enregistrer les retraits | quoi faire quand un paiement réussit |
| **B. Notifications** | envoyer un message WhatsApp (texte, image, fichier) et suivre sa remise | le texte et le destinataire |
| **C. Commerce SaaS** | plans, abonnements, factures, **quotas**, espace client hébergé | qui est le tenant de la requête, et quoi faire quand son abonnement change |
| *Tout ensemble* | les trois ci-dessus partagent le même client, la même signature et le même point de réception | — |

Un site de réservation qui encaisse et reverse à ses partenaires prend **A** (+ **B** pour
confirmer par WhatsApp). Un SaaS qui vend des abonnements prend **C** (+ A si ses clients
paient aussi autre chose qu'un abonnement). Aucun profil n'oblige l'autre.

> Un **tenant** de votre SaaS est un **client payeur** (`customer`) du Core, désigné par la
> référence que vous choisissez (son id). Un **tiers** (partenaire, vendeur, séquestre…) d'un
> profil A est de même désigné par **votre** référence. Vos utilisateurs finaux restent chez
> vous : le Core ne les connaît pas (ADR-0084).

## Avant (une fois, côté plateforme — 10 min)

Un opérateur de la console Core fait ces gestes pour votre projet :

1. **Créer le projet** et émettre une clé d'API (`pk_…`, montrée une seule fois) avec le
   modèle de portées qui correspond à votre profil — « Serveur complet » pour tout prendre.
   **Une application cliente prend « Application cliente »** (ADR-0107 §5) : elle lit ses
   droits, compte son usage, ouvre son portail — et n'a aucune écriture sur le commerce,
   que le propriétaire fait dans le portail.
2. **Profil A** : rien d'autre. **Profil B** : lier le téléphone WhatsApp du projet
   (console › Sessions WhatsApp, QR ou code d'appairage) et noter le nom de la session.
   **Profil C** : publier un plan (`POST /v1/plans`, `/versions`, `/publish`) avec vos quotas,
   par exemple `"features": {"whatsapp": {"type":"limit","meter":"whatsapp_messages","limit":500,"reset":"period","overage":"block","threshold_bps":8000}}`,
   puis régler le portail : `PUT /v1/portal/settings` (nom, logo, couleur, `allowed_return_urls`).
   Aucun moyen de paiement à configurer : le portail propose ceux que la plateforme sait traiter.

## Côté application (20 min)

### 1. Copier le socle (3 min) — pour tous les profils

```
docs/integration/kit-laravel/app/Core/CoreClient.php        →  app/Core/
docs/integration/kit-laravel/app/Core/CoreException.php     →  app/Core/
docs/integration/kit-laravel/app/Core/Webhooks/*            →  app/Core/Webhooks/
docs/integration/kit-laravel/config/core.php                →  config/
```

`.env` :

```
CORE_BASE_URL=https://core.example.com
CORE_API_KEY=pk_…
CORE_WEBHOOK_SECRET=whsec_…
```

Un seul client (`CoreClient`) porte la clé, le délai et la clé d'idempotence ; une seule
exception (`CoreException`) dit ce qui s'est passé :

```php
try {
    $payments->start($payment, key: "order-{$order->id}");
} catch (\App\Core\CoreException $e) {
    $e->isTransient();        // réessayer plus tard, avec la MÊME clé
    $e->is('insufficient-funds');   // l'erreur du Core, en une ligne
    $e->detail;               // la phrase à afficher ou à journaliser
}
```

### 2. Recevoir ce que le Core vous dit (5 min) — pour tous les profils

Collez la route de `routes-snippet.php` et excluez-la du CSRF (`bootstrap/app.php` :
`$middleware->validateCsrfTokens(except: ['webhooks/core'])`). Puis enregistrez l'endpoint
une fois, avec les événements dont votre profil a besoin :

```bash
curl -s -X POST "$CORE_BASE_URL/v1/webhook-endpoints" \
  -H "Authorization: Bearer $CORE_API_KEY" -H "Content-Type: application/json" \
  --data '{"url":"https://votre-app.example/webhooks/core","events":[
    "payment.status_changed","wallet.credited","wallet.debited",
    "notification.delivered","notification.failed",
    "subscription.activated","subscription.renewed","subscription.past_due","subscription.expired",
    "subscription.canceled","subscription.trial_will_end","invoice.paid","usage.threshold_reached","usage.limit_reached"]}'
# → "secret":"whsec_…"  (montré une seule fois → CORE_WEBHOOK_SECRET)
```

Le contrôleur vérifie la signature (rotation de secret comprise), ignore un événement déjà
traité et vous le remet comme **un événement Laravel** — c'est là que votre métier s'accroche :

```php
Event::listen(CoreEventReceived::class, function (CoreEventReceived $event): void {
    if ($event->type === 'payment.status_changed' && $event->data['status'] === 'succeeded') {
        // $event->data['reference'] = la clé d'idempotence que vous avez donnée à start()
        Order::where('payment_key', $event->data['reference'])->first()?->markPaid();
    }
});
```

### Profil A — encaisser et tenir les soldes (5 min)

```
docs/integration/kit-laravel/app/Core/Payments/*   →  app/Core/Payments/
docs/integration/kit-laravel/app/Core/Wallets/*    →  app/Core/Wallets/
```

```php
// 1. Encaisser. `amount` est en plus petite unité (le FCFA n'en a pas : 5000 = 5 000 FCFA).
$payment = app(CorePayments::class)->start([
    'channel' => 'mtn_momo',              // mtn_momo | orange_money | card
    'amount' => 50_000, 'currency' => 'XAF', 'reason' => "Réservation {$booking->ref}",
    'customer_phone_number' => $booking->phone,
    'return_url' => route('booking.done', $booking),
    'wallet_recipient_external_ref' => 'escrow',   // crédite ce tiers quand le paiement réussit
], key: "booking-{$booking->id}", flow: 'paylink');    // paylink : page hébergée · payin : invite sur le téléphone
return redirect()->away($payment['payment_url']);

// 2. Quand `payment.status_changed` arrive avec status = succeeded : répartir.
app(CoreWallets::class)->transfer("settle-{$booking->id}", 'XAF', [
    ['from' => 'escrow', 'to' => "partner:{$partner->id}", 'amount' => 45_000, 'to_party_type' => 'merchant'],
    ['from' => 'escrow', 'to' => 'platform',               'amount' =>  5_000],
], "Règlement {$booking->ref}");

// 3. Lire un solde ou un historique, et enregistrer un retrait que VOUS avez payé.
$wallets = app(CoreWallets::class);
$wallets->available("partner:{$partner->id}", 'XAF');
$wallets->withdraw("payout-{$payout->id}", "partner:{$partner->id}", 20_000, 'XAF', 'Retrait MTN', externalReference: $mtnTransactionId);
```

Les cas courants d'une place de marché se font avec ces trois appels : **payer avec son solde**
(un `transfer` du tiers payeur vers le séquestre — refusé `409 insufficient-funds` sans rien
débiter si le solde a baissé), **rembourser** (un `transfer` du séquestre vers le tiers payeur),
**régler un partenaire** (un `transfer` à plusieurs jambes : sa part, la commission, les frais).

À savoir : l'argent n'entre dans un wallet **que** par un paiement confirmé qui le nomme —
jamais par un crédit libre ; `withdraw()` **enregistre** une sortie faite hors du Core, il ne
verse pas ; la même clé rejouée ne déplace jamais deux fois.

### Profil B — envoyer un message (2 min)

```
docs/integration/kit-laravel/app/Core/Notifications/*   →  app/Core/Notifications/
```

`.env` : `CORE_WHATSAPP_SESSION=nom-de-la-session` (celle liée par l'opérateur). Puis :

```php
app(CoreNotifications::class)->text(
    toPartyRef: "customer-{$user->id}", phone: $user->phone,
    text: 'Votre réservation est confirmée.', clientReference: "booking-{$booking->id}-confirmed",
);   // `clientReference` : envoyé deux fois, remis une fois
```

Le statut final (`delivered`, `read`, `failed`) arrive par `notification.*`. Dans un SaaS du
profil C, chaque message est décompté du quota de l'offre (refus `429 quota-exceeded`).

### Profil C — commerce SaaS (10 min)

```
docs/integration/kit-laravel/app/Core/Billing/*   →  app/Core/Billing/
```

`.env` : `CORE_DEFAULT_PLAN=pro` et `CORE_TRIAL_DAYS=14`.

**Dire qui est le tenant** — dans `EnsureTenantSubscribed::tenantRef()`, une ligne
(`$request->user()->tenant_id`). Même valeur partout.

**Créer le tenant à l'inscription :**

```php
app(\App\Core\Billing\CoreBilling::class)->provision((string) $tenant->id, [
    'name' => $tenant->name, 'email' => $tenant->email, 'phone' => $tenant->phone,
]);   // le tenant démarre sur votre plan par défaut, en essai gratuit
```

**Réagir aux changements d'abonnement** — enregistrez le listener fourni, et éditez
**`TenantAccess::apply()`**, le *seul* code métier du profil : il reçoit `active`, `warning`
(paiement en retard, accès maintenu) ou `blocked`.

```php
Event::listen(CoreEventReceived::class, \App\Core\Billing\ApplyBillingEvent::class);
```

**Protéger ce qui est payant :**

```php
Route::middleware(['auth', 'subscribed'])->group(/* vos routes payantes */);
Route::middleware(['auth', 'subscribed:whatsapp'])->post('/messages', …);   // une fonctionnalité du plan
```

```php
try {
    app(CoreBilling::class)->consume((string) $tenant->id, 'students', key: "student:{$student->id}");
} catch (\App\Core\Billing\QuotaExceeded $e) {
    return back()->withErrors("Limite de votre offre atteinte ({$e->used}/{$e->limit}).");
}
```

**Le bouton « Gérer mon abonnement »** est déjà fait : `GET /billing` ouvre l'espace client du
Core (abonnement, **factures, paiement**, utilisation). L'utilisateur y arrive connecté — aucun
mot de passe de plus — et revient chez vous avec « Retour ».

**Connexion sans passer par votre application** (facultatif, désactivée par défaut) : pour un
client qui arrive depuis un lien de facture, activez *Connexion directe par code* dans la console
(Portail client) ou par `PUT /v1/portal/settings` avec `direct_login_enabled: true` et
`direct_login_session` (le nom d'une session WhatsApp de votre projet). Le client saisit son
téléphone sur `/portal/{project_id}/login` et reçoit un code à 6 chiffres ; cela n'ouvre que le
portail, jamais votre application.

## Vérifier (5 min)

| Je fais… | Je dois voir… |
|---|---|
| Profil A : démarrer un paiement | `payment_url` (paylink) ou `action_hint` (payin) ; en staging, `POST /v1/payments/{id}/simulate` → webhook `payment.status_changed` |
| Profil A : lire un solde après paiement | `available_minor` égal au montant, sur le tiers nommé |
| Profil B : envoyer deux fois la même `clientReference` | un seul message, un seul identifiant |
| Profil C : créer un tenant | `GET /v1/customers/{id}/entitlements` → `active: true`, plan, quotas |
| Profil C : payer la facture | webhook `invoice.paid` → `TenantAccess::apply(…, 'active')` |
| Profil C : dépasser un quota | `QuotaExceeded` ; rien n'est compté |

Pour quitter ou archiver, `GET /v1/payments`, `/v1/notifications` et `/v1/usage` se lisent par curseur et
`POST /v1/exports` (portée `exports:write`) produit l'archive de votre projet — voir le guide curl, §6 bis.

## Ce que le kit garantit pour vous

* **Éprouvé contre le vrai Core** : `tests/Integration/IntegrationKitTest.php` exécute ces
  classes, sans maquette entre elles et le Core ; une évolution de l'API qui casse le kit casse la CI.
* **Rejeu sans danger** : toute opération d'argent porte votre clé ; un événement reçu deux
  fois n'est traité qu'une fois ; un événement livré dans le désordre est ignoré s'il est plus
  ancien que le dernier.
* **Fail-closed** : un tenant inconnu du Core n'a aucun droit ; une signature absente ou un
  secret vide refuse tout.
* **Aucune perte** : si vous ratez un webhook, la lecture des droits (cache 60 s) ou du
  paiement (`get()`) vous remet dans le bon état.
* **Aucun secret d'utilisateur final** ne transite par le Core.
