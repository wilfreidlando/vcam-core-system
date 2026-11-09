@extends('layouts.admin')

@section('title', 'Abonnement')

@section('content')
@php
    $statusLabels = [
        'incomplete' => 'Incomplet',
        'trialing' => 'Essai',
        'active' => 'Actif',
        'past_due' => 'Impayé',
        'paused' => 'En pause',
        'canceled' => 'Annulé',
        'expired' => 'Expiré',
    ];
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <span class="font-mono text-sm text-slate-500">{{ $subscription->customerRef }}</span>
            <span class="ml-2 text-base font-semibold text-slate-900">
                {{ $subscription->planCode }} <span class="text-slate-500">v{{ $subscription->planVersion }}</span>
            </span>
        </div>
        <a href="{{ route('admin.projects.subscriptions.index', $projectId) }}"
           class="inline-block px-2.5 py-1.5 text-sm font-medium text-slate-600 hover:text-slate-900">← Tous les abonnements</a>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <div class="rounded-lg border border-slate-200 bg-white px-4 py-3">
            <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Statut</p>
            <p class="mt-1 text-base font-semibold text-slate-900">{{ $statusLabels[$subscription->status] ?? $subscription->status }}</p>
            <p class="mt-0.5 text-xs text-slate-500">
                @if ($subscription->hasAccess) Droits ouverts @else Aucun droit @endif
            </p>
        </div>
        <div class="rounded-lg border border-slate-200 bg-white px-4 py-3">
            <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Prix signé</p>
            <p class="mt-1 text-base font-semibold text-slate-900 tabular-nums">
                {{ \Core\Support\Money\Money::ofMinor($subscription->priceMinor, $subscription->currency)->groupedAmount() }} {{ $subscription->currency }}
            </p>
            <p class="mt-0.5 text-xs text-slate-500">
                tous les {{ $subscription->intervalCount }} {{ $subscription->intervalUnit }}{{ $subscription->intervalCount > 1 ? 's' : '' }}
            </p>
        </div>
        <div class="rounded-lg border border-slate-200 bg-white px-4 py-3">
            <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Période courante</p>
            <p class="mt-1 text-base font-semibold text-slate-900 tabular-nums">
                @if ($subscription->currentPeriodEnd !== null)
                    {{ \Illuminate\Support\Carbon::parse($subscription->currentPeriodStart)->format('d/m/Y') }}
                    → {{ \Illuminate\Support\Carbon::parse($subscription->currentPeriodEnd)->format('d/m/Y') }}
                @else
                    —
                @endif
            </p>
        </div>
    </div>

    <x-admin.table caption="Périodes de l'abonnement" :headers="['#', 'Type', 'Début', 'Fin', 'Réglée', 'Motif']" empty="Aucune période.">
        @if (count($periods) > 0)
            <tbody class="divide-y divide-slate-100 bg-white">
            @foreach ($periods as $period)
                <tr>
                    <td class="px-4 py-2 text-sm tabular-nums text-slate-600">{{ $period->seq }}</td>
                    <td class="px-4 py-2 text-sm text-slate-700">{{ $period->kind }}</td>
                    <td class="px-4 py-2 text-sm tabular-nums text-slate-600">
                        {{ \Illuminate\Support\Carbon::parse($period->startsAt)->format('d/m/Y') }}
                    </td>
                    <td class="px-4 py-2 text-sm tabular-nums text-slate-600">
                        {{ \Illuminate\Support\Carbon::parse($period->endsAt)->format('d/m/Y') }}
                    </td>
                    <td class="px-4 py-2 text-sm">
                        @if ($period->paidAt !== null)
                            <span class="inline-flex rounded-md bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">oui</span>
                        @else
                            <span class="inline-flex rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">non</span>
                        @endif
                    </td>
                    <td class="px-4 py-2 text-sm text-slate-600">{{ $period->reason ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        @endif
    </x-admin.table>

    @php
        // Défaut #31 : le n° de période à déclarer est sous les yeux de l'opérateur, on le pré-remplit
        // avec la première période non réglée (à défaut, la première du tableau).
        $periodList = collect($periods);
        $payableSeq = $periodList->first(fn ($period) => $period->paidAt === null)?->seq ?? $periodList->first()?->seq;
        $periodOptions = $periodList->mapWithKeys(fn ($period) => [
            $period->seq => 'Période '.$period->seq.' — '.\Illuminate\Support\Carbon::parse($period->startsAt)->format('d/m/Y').' → '.\Illuminate\Support\Carbon::parse($period->endsAt)->format('d/m/Y').($period->paidAt !== null ? ' (réglée)' : ''),
        ])->all();
    @endphp
    <div class="grid gap-4 lg:grid-cols-2">
        <div class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="text-base font-semibold text-slate-900">Suspendre / reprendre</h2>
            <p class="mt-1 text-sm text-slate-600">Un motif est obligatoire : l'acte est audité.</p>
            <div class="mt-3">
                <x-admin.confirm-dialog
                    title="Suspendre cet abonnement ?"
                    message="Les droits du client sont suspendus tant que l'abonnement n'est pas repris. L'acte est audité."
                    :action="route('admin.projects.subscriptions.pause.web', [$projectId, $subscription->id])"
                    confirm="Suspendre">
                    <x-slot:trigger>
                        <button type="button" class="rounded-md bg-indigo-700 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-800">Suspendre</button>
                    </x-slot:trigger>
                    <x-admin.field name="reason" id="pause-reason" label="Motif de la suspension" required maxlength="500" />
                </x-admin.confirm-dialog>
            </div>
            <form method="POST" action="{{ route('admin.projects.subscriptions.resume.web', [$projectId, $subscription->id]) }}"
                  class="mt-3">
                @csrf
                <button type="submit" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    Reprendre (la période est prolongée)
                </button>
            </form>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="text-base font-semibold text-slate-900">Mois offert</h2>
            <p class="mt-1 text-sm text-slate-600">
                Une période <em>complimentary</em> : sans facture, avec un motif.
            </p>
            <form method="POST" action="{{ route('admin.projects.subscriptions.complimentary.web', [$projectId, $subscription->id]) }}"
                  class="mt-3 space-y-3">
                @csrf
                <x-admin.field name="days" id="complimentary-days" type="number" label="Durée offerte (jours)" min="1" max="365" required :value="30" wrapper-class="w-40" />
                <x-admin.field name="reason" id="complimentary-reason" label="Motif" required maxlength="500" />
                <button type="submit" class="rounded-md bg-indigo-700 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-800">
                    Accorder
                </button>
            </form>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="text-base font-semibold text-slate-900">Changer d'offre</h2>
            <p class="mt-1 text-sm text-slate-600">
                À l'échéance par défaut ; immédiat sans prorata.
            </p>
            <form method="POST" action="{{ route('admin.projects.subscriptions.change-plan.web', [$projectId, $subscription->id]) }}"
                  class="mt-3 flex flex-wrap items-end gap-3">
                @csrf
                <x-admin.field name="plan_code" label="Code de l'offre" required maxlength="64" pattern="[a-z0-9][a-z0-9_\-]*" class="font-mono" wrapper-class="min-w-48 flex-1" />
                <x-admin.field name="effective" type="select" label="Prise d'effet" value="period_end"
                               :options="['period_end' => 'à l\'échéance', 'immediate' => 'immédiat']" />
                <button type="submit" class="rounded-md bg-indigo-700 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-800">
                    Changer
                </button>
            </form>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="text-base font-semibold text-slate-900">Packs accordés</h2>
            <p class="mt-1 text-sm text-slate-600">
                Une quantité achetée <strong>en plus</strong> de la limite d'usage, sur le même compteur :
                l'abonnement n'est ni renouvelé ni modifié. La limite n'augmente qu'une fois la
                facture réglée.
            </p>

            @if ($supplements !== [])
                <ul class="mt-3 divide-y divide-slate-200">
                    @foreach ($supplements as $supplement)
                        <li class="flex flex-wrap items-baseline justify-between gap-2 py-2">
                            <span class="text-sm text-slate-900">
                                {{ $supplement->meter }} — {{ $supplement->quantity }} unité(s)
                                @if ($supplement->invoiceNumber)
                                    <span class="text-slate-500">(facture {{ $supplement->invoiceNumber }})</span>
                                @endif
                            </span>
                            <span class="text-xs text-slate-600">
                                {{ $supplement->totalMinor }} {{ $supplement->currency }} —
                                @if ($supplement->status === 'active')
                                    <span class="font-medium text-emerald-700">actif</span>
                                @elseif ($supplement->status === 'pending')
                                    <span class="font-medium text-amber-700">en attente de paiement</span>
                                @else
                                    <span class="font-medium text-slate-500">annulé</span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mt-3 text-sm text-slate-600">Aucun pack accordé à cet abonnement.</p>
            @endif

            @if ($packs !== [])
                <form method="POST" action="{{ route('admin.projects.subscriptions.supplements.grant.web', [$projectId, $subscription->id]) }}"
                      class="mt-4 flex flex-wrap items-end gap-3">
                    @csrf
                    {{-- Le nonce rend le double envoi sans effet : la même
                         clé d'idempotence, donc un seul achat (ADR-0119 §6). --}}
                    <input type="hidden" name="nonce" value="{{ \Illuminate\Support\Str::uuid() }}">
                    <x-admin.field name="pack_code" type="select" label="Pack du catalogue" required
                                   :options="collect($packs)->mapWithKeys(fn ($pack) => [$pack->code => $pack->label.' — '.$pack->quantity.' unité(s)'])->all()" />
                    <x-admin.field name="reason" label="Motif" maxlength="500" optional wrapper-class="min-w-48 flex-1" />
                    <button type="submit" class="rounded-md bg-indigo-700 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-800">
                        Accorder le pack
                    </button>
                </form>
            @else
                <p class="mt-3 text-sm text-slate-600">
                    Ce projet ne propose aucun pack :
                    <a href="{{ route('admin.projects.commerce-settings', $projectId) }}" class="font-medium underline">déclarez-en dans les réglages du commerce</a>.
                </p>
            @endif
        </div>

        <div class="rounded-lg border border-amber-200 bg-amber-50 p-5">
            <h2 class="text-base font-semibold text-amber-900">Déclarer un règlement</h2>
            <p class="mt-1 text-sm text-amber-800">
                Cette déclaration <strong>ne comptabilise aucun mouvement</strong> :
                la facture et l'imputation arrivent avec la facturation. Elle marque la
                période comme réglée.
            </p>
            <form method="POST" action="{{ route('admin.projects.subscriptions.pay.web', [$projectId, $subscription->id]) }}"
                  class="mt-3 flex flex-wrap items-end gap-3">
                @csrf
                @if ($periodOptions !== [])
                    <x-admin.field name="seq" type="select" label="Période à régler" required :value="$payableSeq" :options="$periodOptions" />
                @else
                    <x-admin.field name="seq" type="number" label="N° de période" min="1" required wrapper-class="w-32" />
                @endif
                <x-admin.field name="reference" label="Référence du règlement" maxlength="200" optional wrapper-class="min-w-48 flex-1" />
                <button type="submit" class="rounded-md bg-amber-800 px-3 py-2 text-sm font-semibold text-white hover:bg-amber-900">
                    Déclarer
                </button>
            </form>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="text-base font-semibold text-slate-900">Annuler</h2>
            <p class="mt-1 text-sm text-slate-600">
                À l'échéance, le contrat vit jusqu'à la fin de sa période ; immédiatement,
                il s'arrête.
            </p>
            <div class="mt-3">
                <x-admin.confirm-dialog
                    title="Annuler cet abonnement ?"
                    message="À l'échéance, le contrat s'arrête à la fin de la période en cours ; immédiatement, il s'arrête tout de suite. Cette action est irréversible et journalisée."
                    :action="route('admin.projects.subscriptions.cancel.web', [$projectId, $subscription->id])"
                    confirm="Annuler l'abonnement">
                    <x-slot:trigger>
                        <button type="button" class="rounded-md bg-rose-700 px-3 py-2 text-sm font-semibold text-white hover:bg-rose-800">Annuler l'abonnement</button>
                    </x-slot:trigger>
                    <x-admin.field name="reason" id="cancel-reason" label="Motif" maxlength="500" optional />
                    <x-admin.field name="at_period_end" id="cancel-at-period-end" type="select" label="Prise d'effet" value="1"
                                   :options="['1' => 'à l\'échéance', '0' => 'immédiat']" />
                </x-admin.confirm-dialog>
            </div>
        </div>
    </div>
</div>
@endsection
