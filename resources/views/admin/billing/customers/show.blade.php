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
            <span @class(['rounded-md px-2 py-0.5 text-xs font-medium', 'bg-emerald-100 text-emerald-800' => $customer->status === 'active', 'bg-amber-100 text-amber-800' => $customer->status === 'suspended', 'bg-slate-100 text-slate-700' => ! in_array($customer->status, ['active', 'suspended'], true)])>{{ $customer->status }}</span>
            <a href="{{ route('admin.projects.customers.edit', [$projectId, $customer->externalRef]) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Modifier</a>
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
                    @php $pct = $e->limit ? (int) min(100, round($e->used * 100 / max(1, $e->limit))) : 0; @endphp
                    <div>
                        <div class="flex justify-between text-sm"><span class="font-medium text-slate-800">{{ $e->key }}</span><span class="tabular-nums text-slate-500">{{ number_format((int) $e->used, 0, ',', ' ') }} / {{ $e->limit === null ? '∞' : number_format($e->limit, 0, ',', ' ') }}</span></div>
                        @if ($e->limit !== null)
                            <div class="mt-1 h-2 w-full rounded-full bg-slate-100"><div @class(['h-2 rounded-full', 'bg-emerald-500' => $pct < 80, 'bg-amber-500' => $pct >= 80 && $pct < 100, 'bg-rose-500' => $pct >= 100]) style="width: {{ $pct }}%"></div></div>
                        @endif
                    </div>
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
        <x-admin.table :headers="['Offre', 'Statut', 'Tarif', 'Période en cours', '']" empty="Aucun abonnement.">
            @foreach ($subscriptions as $s)
                <tr>
                    <td class="px-4 py-2 text-sm font-medium">{{ $s->planCode }}</td>
                    <td class="px-4 py-2 text-sm">{{ $s->status }}@if($s->cancelAtPeriodEnd) <span class="text-xs text-amber-700">(ne sera pas renouvelé)</span>@endif</td>
                    <td class="px-4 py-2 text-sm tabular-nums">{{ $money($s->priceMinor, $s->currency) }}</td>
                    <td class="px-4 py-2 text-sm text-slate-500">{{ $date($s->currentPeriodStart) }} → {{ $date($s->currentPeriodEnd) }}</td>
                    <td class="px-4 py-2 text-right"><a href="{{ route('admin.projects.subscriptions.show', [$projectId, $s->id]) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Ouvrir</a></td>
                </tr>
            @endforeach
        </x-admin.table>

        @if ($canSubscribe && count($plans) > 0)
            <form method="POST" action="{{ route('admin.projects.customers.subscribe.web', [$projectId, $customer->externalRef]) }}" class="flex flex-wrap items-end gap-3 border-t border-slate-100 p-5">
                @csrf
                <div>
                    <label for="plan_code" class="block text-sm font-medium text-slate-700">Souscrire à une offre</label>
                    <select id="plan_code" name="plan_code" required class="mt-1 rounded-md border border-slate-300 px-3 py-2 text-sm">
                        @foreach ($plans as $plan)
                            <option value="{{ $plan->code }}">{{ $plan->name }} ({{ $plan->code }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="trial_days" class="block text-sm font-medium text-slate-700">Essai (jours)</label>
                    <input id="trial_days" name="trial_days" type="number" min="0" max="365" placeholder="selon l'offre" class="mt-1 w-32 rounded-md border border-slate-300 px-3 py-2 text-sm">
                </div>
                <button class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">Créer l'abonnement</button>
            </form>
        @endif
    </div>

    <div class="rounded-lg border border-slate-200 bg-white">
        <div class="border-b border-slate-100 px-5 py-3"><h2 class="text-base font-semibold text-slate-900">Factures récentes</h2></div>
        <x-admin.table :headers="['Numéro', 'Statut', 'Total', 'Payé', 'Échéance', '']" empty="Aucune facture.">
            @foreach ($invoices as $i)
                <tr>
                    <td class="px-4 py-2 text-sm font-mono">{{ $i->number ?? '(brouillon)' }}</td>
                    <td class="px-4 py-2 text-sm">{{ $i->status }}</td>
                    <td class="px-4 py-2 text-sm tabular-nums">{{ $money($i->totalMinor, $i->currency) }}</td>
                    <td class="px-4 py-2 text-sm tabular-nums">{{ $money($i->amountPaidMinor, $i->currency) }}</td>
                    <td class="px-4 py-2 text-sm text-slate-500">{{ $date($i->dueAt) }}</td>
                    <td class="px-4 py-2 text-right"><a href="{{ route('admin.projects.invoices.show', [$projectId, $i->id]) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Ouvrir</a></td>
                </tr>
            @endforeach
        </x-admin.table>
    </div>

    <div class="text-right"><a href="{{ route('admin.projects.customers.index', $projectId) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">← Retour aux clients</a></div>
</div>
@endsection
