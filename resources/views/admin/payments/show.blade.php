@extends('layouts.admin')

@section('title', 'Paiement')

@section('content')
<div class="space-y-6">
    <div class="bg-white border border-slate-200 rounded-lg p-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-base">
            <div>
                <p class="text-sm uppercase tracking-wider font-semibold text-slate-500">ID</p>
                <p class="font-mono text-slate-700 break-all">{{ $payment->id }}</p>
            </div>
            <div>
                <p class="text-sm uppercase tracking-wider font-semibold text-slate-500">Projet</p>
                @if ($project !== null)
                    <p class="text-slate-900">
                        <a href="{{ route('admin.projects.show', ['projectId' => $project->id]) }}" class="text-indigo-600 hover:text-indigo-800 font-medium">
                            {{ $project->name }}
                        </a>
                    </p>
                @else
                    <p class="font-mono text-slate-700 break-all">{{ $projectId }}</p>
                @endif
            </div>
            <div>
                <p class="text-sm uppercase tracking-wider font-semibold text-slate-500">Canal</p>
                <p class="font-mono text-slate-700">{{ $payment->channel }}</p>
            </div>
            <div>
                <p class="text-sm uppercase tracking-wider font-semibold text-slate-500">Référence de l'appelant</p>
                {{-- The same value the caller sent as Idempotency-Key —
                     the reference it uses to identify this transaction in
                     its own system, also echoed as app_transaction_ref in
                     the payment.status_changed webhook. --}}
                <p class="font-mono text-slate-700 break-all">{{ $payment->idempotencyKey }}</p>
            </div>
            <div>
                <p class="text-sm uppercase tracking-wider text-slate-500 font-semibold">Statut</p>
                <p>
                    @php $status = $payment->status; @endphp
                    <span @class([
                        'inline-flex rounded-md px-2 py-0.5 font-mono text-xs',
                        'bg-emerald-100 text-emerald-700' => $status === 'succeeded',
                        'bg-rose-100 text-rose-700' => in_array($status, ['failed', 'expired'], true),
                        'bg-slate-100 text-slate-700' => in_array($status, ['pending', 'processing'], true),
                        'bg-amber-100 text-amber-700' => $status === 'refunded',
                        'bg-purple-100 text-purple-700' => $status === 'canceled',
                    ])>
                        {{ $status }}
                    </span>
                </p>
            </div>
            <div>
                <p class="text-sm uppercase tracking-wider text-slate-500 font-semibold">Montant</p>
                {{-- Minor units (G5) → major units, via Money::formattedAmount()
                     — currency-exponent-correct (0 decimals for XAF, the
                     currency this Core actually operates in today, not a
                     hardcoded 2-decimal split). Same pattern as
                     reconciliation/new.blade.php and admin/payments/index. --}}
                <p class="font-mono text-slate-900 tabular-nums">{{ \Core\Support\Money\Money::ofMinor($payment->amount, $payment->currency)->formattedAmount() }} {{ $payment->currency }}</p>
            </div>
            <div>
                <p class="text-sm uppercase tracking-wider text-slate-500 font-semibold">Action requise</p>
                <p class="text-slate-700">{{ $payment->requiresAction ? 'Oui' : 'Non' }}</p>
            </div>
            <div>
                <p class="text-sm uppercase tracking-wider text-slate-500 font-semibold">Indication d'action</p>
                <p class="text-slate-700">{{ $payment->actionHint ?? '—' }}</p>
            </div>
            <div class="md:col-span-2">
                <p class="text-sm uppercase tracking-wider text-slate-500 font-semibold">URL de paiement</p>
                @if ($payment->paymentUrl !== null)
                    <a href="{{ $payment->paymentUrl }}" target="_blank" rel="noopener" class="text-indigo-600 hover:text-indigo-800 font-mono text-sm break-all">
                        {{ $payment->paymentUrl }}
                    </a>
                @else
                    <p class="text-slate-500">—</p>
                @endif
            </div>
            <div class="md:col-span-2">
                <p class="text-sm uppercase tracking-wider text-slate-500 font-semibold">URL de callback</p>
                @if ($payment->callbackUrl !== null)
                    <p class="font-mono text-slate-700 text-sm break-all">{{ $payment->callbackUrl }}</p>
                    <p class="mt-0.5 text-sm text-slate-500">Notification directe à cette URL — pas de relais vers les endpoints webhook du projet pour ce paiement.</p>
                @else
                    <p class="text-slate-500">— (notifié via les endpoints webhook du projet, le cas échéant)</p>
                @endif
            </div>
        </div>
    </div>

    @if (! empty($payment->errorMessage))
        <div class="bg-rose-50 border border-rose-200 rounded-md px-4 py-3 text-sm text-rose-800">
            <span class="font-semibold">Erreur :</span> {{ $payment->errorMessage }}
        </div>
    @endif

    {{-- Réconciliation à la demande pour CE paiement précis — distincte
         du lot batch de la page /admin/reconciliation/new, qui ne traite
         que les paiements que le Core lui-même juge « bloqués ». Ici,
         l'opérateur vérifie directement auprès du provider, quel que
         soit le statut actuel (review finding : « je dois pouvoir
         réconcilier une transaction sélectionnée »). --}}
    @if(app(\Core\Identity\Contracts\OperatorContext::class)->hasPermission('payments:reconcile:write'))
        <div class="bg-white border border-slate-200 rounded-lg p-4 flex items-center justify-between gap-4">
            <p class="text-sm text-slate-600">
                Vérifie le statut réel de ce paiement directement auprès de son
                provider et met à jour le Core si besoin — utile si un webhook
                a pu être manqué.
            </p>
            <form method="POST" action="{{ route('admin.reconciliation.run-one', ['payment' => $payment->id]) }}">
                @csrf
                <button type="submit" class="shrink-0 inline-flex items-center gap-2 rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2 text-sm font-medium text-white shadow-sm">
                    Réconcilier maintenant
                </button>
            </form>
        </div>
    @endif

    @include('admin.payments.partials.journey')

    <div class="text-right">
        <a href="{{ route('admin.payments.index') }}" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">
            ← Retour aux paiements
        </a>
    </div>
</div>
@endsection
