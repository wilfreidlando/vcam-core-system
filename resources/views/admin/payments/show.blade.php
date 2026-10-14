@extends('layouts.admin')

@section('title', 'Paiement')

@section('content')
<div class="space-y-6">
    {{-- ADR-0062 — toutes les données du paiement, sans exception. --}}
    @php
        $field = 'text-sm uppercase tracking-wider font-semibold text-slate-500';
        $fmt = static fn (?string $iso): string => $iso !== null ? \Illuminate\Support\Carbon::parse($iso)->format('d/m/Y H:i:s') : '—';
    @endphp
    <div class="bg-white border border-slate-200 rounded-lg p-6">
        <div class="flex flex-wrap items-center gap-3 mb-5">
            <x-admin.status-badge :status="$payment->status" :final="$payment->isFinal" />
            
            <span class="text-2xl font-semibold text-slate-900 tabular-nums">{{ \Core\Support\Money\Money::ofMinor($payment->amount, $payment->currency)->formattedAmount() }} {{ $payment->currency }}</span>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-x-6 gap-y-4 text-base">
            <div class="md:col-span-2">
                <p class="{{ $field }}">ID</p>
                <p class="font-mono text-slate-700 break-all">{{ $payment->id }}</p>
            </div>
            <div>
                <p class="{{ $field }}">Projet</p>
                @if ($project !== null)
                    <a href="{{ route('admin.projects.show', ['projectId' => $project->id]) }}" class="text-indigo-600 hover:text-indigo-800 font-medium">{{ $project->name }}</a>
                @else
                    <p class="font-mono text-slate-700 break-all">{{ $projectId }}</p>
                @endif
            </div>
            <div>
                <p class="{{ $field }}">Créé le</p>
                <p class="text-slate-800 tabular-nums">{{ $fmt($payment->createdAt) }}</p>
            </div>
            <div>
                <p class="{{ $field }}">Dernière mise à jour</p>
                <p class="text-slate-800 tabular-nums">{{ $fmt($payment->updatedAt) }}</p>
            </div>
            <div>
                <p class="{{ $field }}">Canal</p>
                <p class="font-mono text-slate-700">{{ $payment->channel }}</p>
            </div>
            <div>
                <p class="{{ $field }}">Provider</p>
                <p class="font-mono text-slate-700">{{ $payment->providerCode ?? '—' }}</p>
            </div>
            <div class="md:col-span-2">
                <p class="{{ $field }}">Référence provider</p>
                <p class="font-mono text-slate-700 break-all">{{ $payment->providerTransactionRef ?? '—' }}</p>
            </div>
            <div class="md:col-span-3">
                <p class="{{ $field }}">Référence de l'intégrateur (Idempotency-Key)</p>
                <p class="font-mono text-slate-700 break-all">{{ $payment->idempotencyKey }}</p>
            </div>
            <div>
                <p class="{{ $field }}">Action requise</p>
                <p class="text-slate-700">{{ $payment->requiresAction ? 'Oui' : 'Non' }}</p>
            </div>
            <div class="md:col-span-2">
                <p class="{{ $field }}">Indication d'action</p>
                <p class="text-slate-700">{{ $payment->actionHint ?? '—' }}</p>
            </div>
            <div class="md:col-span-3">
                <p class="{{ $field }}">URL de paiement</p>
                @if ($payment->paymentUrl !== null)
                    <a href="{{ $payment->paymentUrl }}" target="_blank" rel="noopener" class="text-indigo-600 hover:text-indigo-800 font-mono text-sm break-all">{{ $payment->paymentUrl }}</a>
                @else
                    <p class="text-slate-500">—</p>
                @endif
            </div>
            <div class="md:col-span-3">
                <p class="{{ $field }}">URL de retour (navigateur)</p>
                <p class="font-mono text-slate-700 text-sm break-all">{{ $payment->returnUrl ?? '—' }}</p>
            </div>
            <div class="md:col-span-3">
                <p class="{{ $field }}">URL de callback</p>
                @if ($payment->callbackUrl !== null)
                    <p class="font-mono text-slate-700 text-sm break-all">{{ $payment->callbackUrl }}</p>
                    <p class="mt-0.5 text-sm text-slate-500">Notification directe à cette URL — pas de relais vers les endpoints webhook du projet pour ce paiement.</p>
                @else
                    <p class="text-slate-500">— (notifié via les endpoints webhook du projet, le cas échéant)</p>
                @endif
            </div>
            <div class="md:col-span-3">
                <p class="{{ $field }}">Wallet à créditer</p>
                <p class="font-mono text-slate-700">{{ $payment->walletRecipientExternalRef ?? '— (encaissement pur, aucun wallet)' }}</p>
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
