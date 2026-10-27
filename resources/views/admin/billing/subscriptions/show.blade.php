@extends('layouts.admin')

@section('title', 'Abonnement')

@section('content')
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <span class="font-mono text-sm text-slate-500">{{ $subscription->customerRef }}</span>
            <span class="ml-2 text-base font-semibold text-slate-900">
                {{ $subscription->planCode }} <span class="text-slate-400">v{{ $subscription->planVersion }}</span>
            </span>
        </div>
        <a href="{{ route('admin.projects.subscriptions.index', $projectId) }}"
           class="text-sm font-medium text-slate-600 hover:text-slate-900">← Tous les abonnements</a>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <div class="rounded-lg border border-slate-200 bg-white px-4 py-3">
            <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Statut</p>
            <p class="mt-1 text-base font-semibold text-slate-900">{{ $subscription->status }}</p>
            <p class="mt-0.5 text-xs text-slate-500">
                @if ($subscription->hasAccess) Droits ouverts @else Aucun droit @endif
            </p>
        </div>
        <div class="rounded-lg border border-slate-200 bg-white px-4 py-3">
            <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Prix signé</p>
            <p class="mt-1 text-base font-semibold text-slate-900 tabular-nums">
                {{ \Core\Support\Money\Money::ofMinor($subscription->priceMinor, $subscription->currency)->formattedAmount() }} {{ $subscription->currency }}
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

    <x-admin.table :headers="['#', 'Type', 'Début', 'Fin', 'Réglée', 'Motif']" empty="Aucune période.">
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

    <div class="grid gap-4 lg:grid-cols-2">
        <div class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="text-base font-semibold text-slate-900">Suspendre / reprendre</h2>
            <p class="mt-1 text-sm text-slate-600">Un motif est obligatoire : l'acte est audité.</p>
            <form method="POST" action="{{ route('admin.projects.subscriptions.pause.web', [$projectId, $subscription->id]) }}"
                  class="mt-3 flex items-center gap-2">
                @csrf
                <input name="reason" required placeholder="Motif de la suspension" maxlength="500"
                       class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                <button type="submit" class="rounded-md bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-700">
                    Suspendre
                </button>
            </form>
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
                  class="mt-3 space-y-2">
                @csrf
                <input name="days" type="number" min="1" max="365" value="30" required
                       class="w-28 rounded-md border border-slate-300 px-3 py-2 text-sm">
                <input name="reason" required placeholder="Motif" maxlength="500"
                       class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                <button type="submit" class="rounded-md bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-700">
                    Accorder
                </button>
            </form>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="text-base font-semibold text-slate-900">Changer de plan</h2>
            <p class="mt-1 text-sm text-slate-600">
                À l'échéance par défaut ; immédiat sans prorata.
            </p>
            <form method="POST" action="{{ route('admin.projects.subscriptions.change-plan.web', [$projectId, $subscription->id]) }}"
                  class="mt-3 flex items-center gap-2">
                @csrf
                <input name="plan_code" required placeholder="code du plan" maxlength="64"
                       class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-mono">
                <select name="effective" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
                    <option value="period_end">à l'échéance</option>
                    <option value="immediate">immédiat</option>
                </select>
                <button type="submit" class="rounded-md bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-700">
                    Changer
                </button>
            </form>
        </div>

        <div class="rounded-lg border border-amber-200 bg-amber-50 p-5">
            <h2 class="text-base font-semibold text-amber-900">Déclarer un règlement</h2>
            <p class="mt-1 text-sm text-amber-800">
                Cette déclaration <strong>n'écrit aucun mouvement au grand livre</strong> :
                la facture et l'imputation arrivent avec la facturation. Elle marque la
                période comme réglée.
            </p>
            <form method="POST" action="{{ route('admin.projects.subscriptions.pay.web', [$projectId, $subscription->id]) }}"
                  class="mt-3 flex items-center gap-2">
                @csrf
                <input name="seq" type="number" min="1" required placeholder="n° de période"
                       class="w-28 rounded-md border border-amber-300 px-3 py-2 text-sm">
                <input name="reference" placeholder="référence du règlement" maxlength="200"
                       class="w-full rounded-md border border-amber-300 px-3 py-2 text-sm">
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
            <form method="POST" action="{{ route('admin.projects.subscriptions.cancel.web', [$projectId, $subscription->id]) }}"
                  class="mt-3 flex items-center gap-2">
                @csrf
                <input name="reason" placeholder="Motif (facultatif)" maxlength="500"
                       class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                <select name="at_period_end" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
                    <option value="1">à l'échéance</option>
                    <option value="0">immédiat</option>
                </select>
                <button type="submit" class="rounded-md bg-rose-700 px-3 py-2 text-sm font-semibold text-white hover:bg-rose-800">
                    Annuler
                </button>
            </form>
        </div>
    </div>
</div>
@endsection
