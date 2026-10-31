@extends('layouts.admin')

@section('title', 'Abonnements')

@section('content')
<div class="space-y-6">
    <div class="rounded-lg border border-indigo-100 bg-indigo-50 px-4 py-3.5 text-sm text-indigo-900 leading-relaxed">
        <p class="font-semibold mb-1">Un contrat, des périodes</p>
        <p>
            Un abonnement est un contrat entre un client et le projet, renouvelé par
            périodes. Deux périodes du même contrat ne peuvent jamais se chevaucher : un
            paiement anticipé solde la période à venir, il n'en crée pas une seconde.
        </p>
    </div>

    <x-admin.table caption="Abonnements du projet" :headers="['Client', 'Offre', 'Prix', 'Statut', 'Période courante', 'Échéance', '']" empty="Aucun abonnement.">
        @if (count($subscriptions) > 0)
            <tbody class="divide-y divide-slate-100 bg-white">
            @foreach ($subscriptions as $subscription)
                <tr>
                    <td class="px-4 py-2 text-sm font-mono text-slate-700">{{ $subscription->customerRef }}</td>
                    <td class="px-4 py-2 text-sm text-slate-700">
                        {{ $subscription->planCode }} <span class="text-slate-500">v{{ $subscription->planVersion }}</span>
                    </td>
                    <td class="px-4 py-2 text-base text-slate-900 tabular-nums whitespace-nowrap">
                        {{ \Core\Support\Money\Money::ofMinor($subscription->priceMinor, $subscription->currency)->groupedAmount() }} {{ $subscription->currency }}
                    </td>
                    <td class="px-4 py-2 text-sm">
                        @php
                            $labels = [
                                'incomplete' => 'Incomplet',
                                'trialing' => 'Essai',
                                'active' => 'Actif',
                                'past_due' => 'Impayé',
                                'paused' => 'En pause',
                                'canceled' => 'Annulé',
                                'expired' => 'Expiré',
                            ];
                            $classes = [
                                'incomplete' => 'bg-slate-100 text-slate-700',
                                'trialing' => 'bg-indigo-100 text-indigo-700',
                                'active' => 'bg-emerald-100 text-emerald-700',
                                'past_due' => 'bg-amber-100 text-amber-700',
                                'paused' => 'bg-sky-100 text-sky-700',
                                'canceled' => 'bg-slate-200 text-slate-700',
                                'expired' => 'bg-rose-100 text-rose-700',
                            ];
                        @endphp
                        <span class="inline-flex rounded-md px-2 py-0.5 text-xs font-medium {{ $classes[$subscription->status] ?? 'bg-slate-100 text-slate-700' }}">
                            {{ $labels[$subscription->status] ?? $subscription->status }}
                        </span>
                    </td>
                    <td class="px-4 py-2 text-sm text-slate-600 tabular-nums whitespace-nowrap">
                        @if ($subscription->currentPeriodEnd !== null)
                            jusqu'au {{ \Illuminate\Support\Carbon::parse($subscription->currentPeriodEnd)->format('d/m/Y') }}
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-4 py-2 text-sm text-slate-600 tabular-nums whitespace-nowrap">
                        @if ($subscription->trialEndsAt !== null)
                            essai jusqu'au {{ \Illuminate\Support\Carbon::parse($subscription->trialEndsAt)->format('d/m/Y') }}
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-4 py-2 text-sm text-right">
                        <a href="{{ route('admin.projects.subscriptions.show', [$projectId, $subscription->id]) }}"
                           class="inline-block px-2.5 py-1.5 font-medium text-indigo-700 hover:text-indigo-900">Ouvrir</a>
                    </td>
                </tr>
            @endforeach
            </tbody>
        @endif
    </x-admin.table>

    @include('admin.billing.partials.cursor-pager', ['routeName' => 'admin.projects.subscriptions.index', 'routeParams' => [$projectId], 'nextCursor' => $nextCursor])
</div>
@endsection
