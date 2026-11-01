@extends('layouts.admin')

@section('title', $customer->displayName)

@section('content')
@php
    $zero = ['XAF', 'XOF', 'JPY', 'KRW', 'RWF', 'GNF', 'UGX', 'VND'];
    $money = fn (int $minor, string $currency): string => in_array($currency, $zero, true)
        ? number_format($minor, 0, ',', ' ').' '.$currency
        : number_format($minor / 100, 2, ',', ' ').' '.$currency;
    $date = fn (?string $iso): string => $iso ? \Illuminate\Support\Carbon::parse($iso)->format('d/m/Y') : '—';
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white p-5">
        <div>
            <p class="text-xl font-semibold text-slate-900">{{ $customer->displayName }}</p>
            <p class="font-mono text-sm text-slate-500">{{ $customer->externalRef }}</p>
        </div>
        <div class="flex items-center gap-3">
            <span @class(['rounded-md px-2 py-0.5 text-xs font-medium', 'bg-emerald-100 text-emerald-800' => $customer->status === 'active', 'bg-amber-100 text-amber-800' => $customer->status === 'suspended', 'bg-slate-100 text-slate-700' => ! in_array($customer->status, ['active', 'suspended'], true)])>{{ \App\Http\View\CustomerLabels::status($customer->status) }}</span>
            <a href="{{ route('admin.projects.customers.edit', [$projectId, $customer->externalRef]) }}" class="inline-block px-2.5 py-1.5 text-sm font-medium text-indigo-700 hover:text-indigo-900">Modifier</a>
        </div>
    </div>

    @if ($rights)
        <div class="rounded-lg border border-slate-200 bg-white p-5 space-y-3">
            <div class="flex items-center justify-between">
                <h2 class="text-base font-semibold text-slate-900">Droits et usage</h2>
                <span @class(['rounded-md px-2 py-0.5 text-xs font-medium', 'bg-emerald-100 text-emerald-800' => $rights->active, 'bg-rose-100 text-rose-800' => ! $rights->active])>{{ $rights->active ? 'Accès ouvert · offre '.$rights->planCode : 'Aucun droit actif' }}</span>
            </div>
            @foreach ($rights->entitlements as $e)
                @if ($e->type === 'limit')
                    <x-admin.meter :label="$e->key" :used="(int) $e->used" :limit="$e->limit === null ? null : (int) $e->limit" :id="'meter-ent-'.$loop->index" />
                @else
                    <p class="text-sm text-slate-700">{{ $e->enabled ? '✓' : '✗' }} {{ $e->key }}</p>
                @endif
            @endforeach
        </div>
    @endif

    <div class="rounded-lg border border-slate-200 bg-white">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
            <h2 class="text-base font-semibold text-slate-900">Abonnements</h2>
        </div>
        <x-admin.table caption="Abonnements du client" :headers="['Offre', 'Statut', 'Tarif', 'Période en cours', '']" empty="Aucun abonnement.">
            @foreach ($subscriptions as $s)
                <tr>
                    <td class="px-4 py-2 text-sm font-medium">{{ $s->planCode }}</td>
                    <td class="px-4 py-2 text-sm">{{ $s->status }}@if($s->cancelAtPeriodEnd) <span class="text-xs text-amber-700">(ne sera pas renouvelé)</span>@endif</td>
                    <td class="px-4 py-2 text-sm tabular-nums">{{ $money($s->priceMinor, $s->currency) }}</td>
                    <td class="px-4 py-2 text-sm text-slate-500">{{ $date($s->currentPeriodStart) }} → {{ $date($s->currentPeriodEnd) }}</td>
                    <td class="px-4 py-2 text-right"><a href="{{ route('admin.projects.subscriptions.show', [$projectId, $s->id]) }}" class="inline-block px-2.5 py-1.5 text-sm font-medium text-indigo-700 hover:text-indigo-900">Ouvrir</a></td>
                </tr>
            @endforeach
        </x-admin.table>

        @if ($canSubscribe && count($plans) > 0)
            <form method="POST" action="{{ route('admin.projects.customers.subscribe.web', [$projectId, $customer->externalRef]) }}" class="flex flex-wrap items-end gap-3 border-t border-slate-100 p-5">
                @csrf
                <x-admin.field name="plan_code" type="select" label="Souscrire à une offre" required
                               :options="collect($plans)->mapWithKeys(fn ($plan) => [$plan->code => $plan->name.' ('.$plan->code.')'])->all()" />
                <x-admin.field name="trial_days" type="number" label="Essai (jours)" min="0" max="365" optional
                               placeholder="selon l'offre" wrapper-class="w-40" />
                <button type="submit" class="rounded-md bg-indigo-700 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-800">Créer l'abonnement</button>
            </form>
        @endif
    </div>

    <div class="rounded-lg border border-slate-200 bg-white">
        <div class="border-b border-slate-100 px-5 py-3"><h2 class="text-base font-semibold text-slate-900">Factures récentes</h2></div>
        <x-admin.table caption="Factures récentes du client" :headers="['Numéro', 'Statut', 'Total', 'Payé', 'Échéance', '']" empty="Aucune facture.">
            @foreach ($invoices as $i)
                <tr>
                    <td class="px-4 py-2 text-sm font-mono">{{ $i->number ?? '(brouillon)' }}</td>
                    <td class="px-4 py-2 text-sm">{{ $i->status }}</td>
                    <td class="px-4 py-2 text-sm tabular-nums">{{ $money($i->totalMinor, $i->currency) }}</td>
                    <td class="px-4 py-2 text-sm tabular-nums">{{ $money($i->amountPaidMinor, $i->currency) }}</td>
                    <td class="px-4 py-2 text-sm text-slate-500">{{ $date($i->dueAt) }}</td>
                    <td class="px-4 py-2 text-right"><a href="{{ route('admin.projects.invoices.show', [$projectId, $i->id]) }}" class="inline-block px-2.5 py-1.5 text-sm font-medium text-indigo-700 hover:text-indigo-900">Ouvrir</a></td>
                </tr>
            @endforeach
        </x-admin.table>
    </div>

    {{-- ADR-0073 §2, §4 — ce que le client a réellement réglé : le paiement en ligne et l'encaissement hors ligne, lus dans une seule chronologie. --}}
    @if ($canSeeSettlements)
        @php
            $settlementMethods = [
                'online' => 'Paiement en ligne',
                'bank_transfer' => 'Virement',
                'cash' => 'Espèces',
                'cheque' => 'Chèque',
                'other' => 'Autre',
            ];
            // Les deux kinds n'ont pas le même vocabulaire : une tentative
            // de paiement « en cours » n'est pas un reçu « en attente ».
            $settlementStatuses = [
                'payment.pending' => 'En cours',
                'payment.succeeded' => 'Comptabilisé',
                'payment.failed' => 'Échec',
                'payment.canceled' => 'Annulé',
                'receipt.pending' => 'En attente',
                'receipt.approved' => 'Comptabilisé',
                'receipt.rejected' => 'Refusé',
            ];
            $settlementTones = [
                'payment.succeeded' => 'bg-emerald-100 text-emerald-700',
                'receipt.approved' => 'bg-emerald-100 text-emerald-700',
                'payment.pending' => 'bg-amber-100 text-amber-700',
                'receipt.pending' => 'bg-amber-100 text-amber-700',
                'payment.failed' => 'bg-rose-100 text-rose-700',
                'receipt.rejected' => 'bg-rose-100 text-rose-700',
                'payment.canceled' => 'bg-slate-200 text-slate-700',
            ];
        @endphp
        <div class="rounded-lg border border-slate-200 bg-white">
            <div class="border-b border-slate-100 px-5 py-3"><h2 class="text-base font-semibold text-slate-900">Règlements</h2></div>
            <x-admin.table caption="Règlements du client" :headers="['Facture', 'Origine', 'Montant', 'Référence', 'Date', 'État']" empty="aucun règlement">
                @foreach ($settlements as $settlement)
                    <tr>
                        <td class="px-4 py-2 text-sm font-mono">{{ $settlement->invoiceNumber ?? '(brouillon)' }}</td>
                        <td class="px-4 py-2 text-sm text-slate-700">{{ $settlementMethods[$settlement->method] ?? $settlement->method }}</td>
                        <td class="px-4 py-2 text-sm text-slate-900 tabular-nums whitespace-nowrap">
                            {{ \Core\Support\Money\Money::ofMinor($settlement->amountMinor, $settlement->currency)->groupedAmount() }}
                            {{ $settlement->currency }}
                        </td>
                        <td class="px-4 py-2 text-sm font-mono text-slate-700">{{ $settlement->reference }}</td>
                        <td class="px-4 py-2 text-sm text-slate-500 tabular-nums whitespace-nowrap">{{ $date($settlement->occurredAt) }}</td>
                        <td class="px-4 py-2 text-sm whitespace-nowrap">
                            <span class="inline-flex rounded-md px-2 py-0.5 text-xs font-medium {{ $settlementTones[$settlement->kind.'.'.$settlement->status] ?? 'bg-slate-100 text-slate-700' }}">
                                {{ $settlementStatuses[$settlement->kind.'.'.$settlement->status] ?? $settlement->status }}
                            </span>
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>
        </div>
    @endif

    <div class="text-right"><a href="{{ route('admin.projects.customers.index', $projectId) }}" class="inline-block px-2.5 py-1.5 text-sm font-medium text-indigo-700 hover:text-indigo-900">← Retour aux clients</a></div>
</div>
@endsection
