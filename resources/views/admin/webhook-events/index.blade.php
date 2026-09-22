@extends('layouts.admin')

@section('title', "Types d'événements webhook")

@section('content')
<div class="max-w-4xl space-y-6">

    <div class="rounded-lg border border-indigo-100 bg-indigo-50 px-4 py-3.5 text-sm text-indigo-900 leading-relaxed">
        <p class="font-semibold mb-1">Un endpoint reçoit plusieurs types d'événements sur la même URL</p>
        <p>
            Si votre endpoint est abonné à <span class="font-mono">*</span> (tous les événements) ou à
            plusieurs types à la fois, chaque livraison peut être d'un type différent. Le corps JSON
            s'auto-décrit toujours — vérifiez le champ <span class="font-mono">type</span> avant d'interpréter
            <span class="font-mono">data</span>, ne devinez jamais la forme du payload.
        </p>
    </div>

    <div class="bg-white border border-slate-200 rounded-lg p-6">
        <h2 class="text-lg font-semibold text-slate-900 mb-2">Format de chaque livraison</h2>
        <p class="text-base text-slate-600 mb-3">
            Toutes les livraisons — quel que soit le type d'événement — ont exactement cette forme :
        </p>
        <pre class="text-sm font-mono bg-slate-50 border border-slate-200 rounded px-3 py-2.5 overflow-x-auto">{
  "id": "&lt;identifiant unique de l'événement&gt;",
  "type": "&lt;un des types listés ci-dessous&gt;",
  "data": { ... forme spécifique au type, voir plus bas ... }
}</pre>
        <p class="text-base text-slate-600 mt-3 mb-1">
            Chaque requête porte aussi ces en-têtes, redondants avec <span class="font-mono">id</span>/<span class="font-mono">type</span> mais utiles pour router sans parser le corps :
        </p>
        <ul class="text-sm text-slate-600 font-mono space-y-0.5 list-disc list-inside">
            <li>X-Event-Id</li>
            <li>X-Event-Type</li>
            <li>X-Timestamp</li>
            <li>X-Signature</li>
        </ul>
        <p class="text-base text-slate-600 mt-3">
            <span class="font-semibold">Vérification de signature :</span> <span class="font-mono">X-Signature = HMAC-SHA256(secret, "{X-Timestamp}.{corps brut}")</span>,
            en hexadécimal minuscule. Le secret est celui affiché une seule fois à l'enregistrement de l'endpoint.
        </p>
    </div>

    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="px-5 py-3.5 border-b border-slate-200 bg-slate-50/60">
            <h2 class="font-semibold text-slate-900">payment.status_changed</h2>
            <p class="text-sm text-slate-500 mt-0.5">Le statut d'un paiement a changé (succès, échec, expiration, remboursement, annulation…).</p>
        </div>
        <div class="p-5 space-y-3">
            <pre class="text-sm font-mono bg-slate-50 border border-slate-200 rounded px-3 py-2.5 overflow-x-auto">{
  "id": "...",
  "type": "payment.status_changed",
  "data": {
    "provider_code": "my-coolpay",
    "transaction_ref": "...",
    "app_transaction_ref": "...",
    "status": "succeeded",
    "reason": null,
    "occurred_at": "2026-09-21T21:52:04Z"
  }
}</pre>
            <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-900">
                <span class="font-semibold">Priorité au callback_url du paiement.</span> Si le paiement qui a
                déclenché ce statut portait un <span class="font-mono">callback_url</span> (fourni à
                l'initiation), <span class="font-semibold">cette livraison n'est PAS envoyée</span> aux endpoints
                webhook du projet — seule la notification directe au <span class="font-mono">callback_url</span>
                a lieu. Un endpoint abonné à ce type ne voit donc que les paiements initiés
                <em>sans</em> callback_url propre.
            </div>
        </div>
    </div>

    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="px-5 py-3.5 border-b border-slate-200 bg-slate-50/60">
            <h2 class="font-semibold text-slate-900">wallet.credited</h2>
            <p class="text-sm text-slate-500 mt-0.5">Un wallet vient d'être crédité — aujourd'hui, toujours en conséquence directe et synchrone d'un paiement réussi qui portait <span class="font-mono">wallet_recipient_external_ref</span>.</p>
        </div>
        <div class="p-5 space-y-3">
            <pre class="text-sm font-mono bg-slate-50 border border-slate-200 rounded px-3 py-2.5 overflow-x-auto">{
  "id": "...",
  "type": "wallet.credited",
  "data": {
    "wallet_id": "...",
    "party_id": "...",
    "amount_minor": 100,
    "currency": "XAF"
  }
}</pre>
            <p class="text-sm text-slate-600">
                <span class="font-semibold">Redondant avec payment.status_changed pour un paiement que vous avez
                vous-même initié</span> : vous savez déjà, au moment où vous initiez le paiement avec
                <span class="font-mono">wallet_recipient_external_ref</span>, qu'un succès créditera le wallet — la
                confirmation du paiement suffit. Cet événement sert surtout à un abonnement transverse
                (tableau de bord de trésorerie, réconciliation comptable) qui suit tous les mouvements de wallets,
                indépendamment du paiement à l'origine.
            </p>
        </div>
    </div>

    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="px-5 py-3.5 border-b border-slate-200 bg-slate-50/60">
            <h2 class="font-semibold text-slate-900">wallet.debited</h2>
            <p class="text-sm text-slate-500 mt-0.5">Un wallet vient d'être débité.</p>
        </div>
        <div class="p-5 space-y-3">
            <pre class="text-sm font-mono bg-slate-50 border border-slate-200 rounded px-3 py-2.5 overflow-x-auto">{
  "id": "...",
  "type": "wallet.debited",
  "data": {
    "wallet_id": "...",
    "party_id": "...",
    "amount_minor": 100,
    "currency": "XAF"
  }
}</pre>
            <div class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm text-rose-900">
                <span class="font-semibold">Ne vous abonnez pas à celui-ci pour l'instant.</span> Aucun flux
                métier réel ne débite un wallet aujourd'hui — le seul déclencheur existant est un outil interne
                de test de concurrence. Cet événement ne fera rien en pratique tant qu'aucune fonctionnalité de
                paiement sortant n'existe.
            </div>
        </div>
    </div>

    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="px-5 py-3.5 border-b border-slate-200 bg-slate-50/60">
            <h2 class="font-semibold text-slate-900">notification.delivered / notification.read / notification.failed</h2>
            <p class="text-sm text-slate-500 mt-0.5">Le statut d'une notification envoyée via <span class="font-mono">POST /v1/notifications/{text,image,file}</span> a changé.</p>
        </div>
        <div class="p-5 space-y-3">
            <pre class="text-sm font-mono bg-slate-50 border border-slate-200 rounded px-3 py-2.5 overflow-x-auto">{
  "id": "...",
  "type": "notification.failed",
  "data": {
    "notification_id": "...",
    "channel": "whatsapp",
    "status": "failed",
    "reason": "..."
  }
}</pre>
            <p class="text-sm text-slate-600">
                Les trois types partagent exactement cette forme (seul <span class="font-mono">status</span>
                change : <span class="font-mono">delivered</span>, <span class="font-mono">read</span> ou
                <span class="font-mono">failed</span>) — y compris quand l'échec vient de l'épuisement de tous
                les providers en cascade à l'envoi, pas seulement d'un callback provider tardif.
            </p>
        </div>
    </div>

    <div class="text-right">
        <a href="{{ route('admin.webhook-deliveries.dead.web') }}" class="text-base text-indigo-600 hover:text-indigo-800 font-medium">
            ← Retour aux livraisons de webhooks
        </a>
    </div>
</div>
@endsection
