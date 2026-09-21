@extends('layouts.admin')

@section('title', 'Lancer une réconciliation')

@section('content')
<div class="max-w-2xl mx-auto space-y-6">

    {{-- Preview: exactly which payments a run would touch, before the
         operator commits to running it (review finding: reconciliation
         used to run blind, with no visibility into what it would
         check). --}}
    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200">
            <h2 class="font-semibold text-slate-900">
                @if (count($previewPayments) > 0)
                    Ces {{ count($previewPayments) }} paiement{{ count($previewPayments) === 1 ? '' : 's' }} {{ count($previewPayments) === 1 ? 'sera vérifié' : 'seront vérifiés' }} au lancement de la réconciliation
                @else
                    Aucun paiement n'a besoin de réconciliation pour le moment
                @endif
            </h2>
        </div>

        @if (count($previewPayments) > 0)
            <x-admin.table :headers="['ID', 'Projet', 'Canal', 'Statut', 'Montant', 'Bloqué depuis']" :empty="''">
                @foreach ($previewPayments as $payment)
                    <tr>
                        <td class="px-4 py-2 text-xs font-mono text-slate-700" title="{{ $payment->id }}">
                            {{ substr($payment->id, 0, 8) }}…
                        </td>
                        <td class="px-4 py-2 text-sm text-slate-900 truncate max-w-[10rem]" title="{{ $payment->projectId }}">
                            {{ $projectNames[$payment->projectId] ?? substr($payment->projectId, 0, 8).'…' }}
                        </td>
                        <td class="px-4 py-2 text-sm text-slate-700">{{ $payment->channel }}</td>
                        <td class="px-4 py-2 text-xs">
                            <span class="inline-flex rounded-md bg-amber-100 px-2 py-0.5 font-mono text-amber-700">
                                {{ $payment->status }}
                            </span>
                        </td>
                        {{-- Minor units -> major units assumes a 2-decimal
                             currency (true for every currency this Core
                             currently routes payments in). --}}
                        <td class="px-4 py-2 text-sm font-mono text-slate-900 tabular-nums text-right">
                            {{ number_format($payment->amount / 100, 2) }} {{ $payment->currency }}
                        </td>
                        {{-- How long this payment has actually sat
                             untouched — the detail an operator needs to
                             judge whether the run is worth trusting, not
                             just which rows it would touch. --}}
                        <td class="px-4 py-2 text-xs text-slate-500 whitespace-nowrap" title="{{ $payment->updatedAt?->format('Y-m-d H:i:s') ?? '' }}">
                            {{-- Payment::$updatedAt is a native DateTimeImmutable
                                 (Domain layer stays framework-free, G3b) — wrap
                                 in Carbon here, in the view, just for the
                                 human-readable diff. --}}
                            {{ $payment->updatedAt !== null ? \Illuminate\Support\Carbon::instance($payment->updatedAt)->diffForHumans() : '—' }}
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>
        @endif
    </div>

    <div class="bg-white border border-slate-200 rounded-lg p-6">

        <h2 class="text-base font-semibold text-slate-900 mb-1">Déclencher la réconciliation</h2>
        <p class="text-sm text-slate-600 mb-4">
            Exécute de façon synchrone le même traitement que la tâche
            planifiée
            (<code class="text-xs bg-slate-100 px-1.5 py-0.5 rounded">admin:reconcile-stuck-payments</code>),
            avec un plafond strict de 20 lignes par appel pour garder une
            réponse HTTP rapide.
        </p>

        <form method="POST" action="{{ route('admin.reconciliation.run') }}" class="space-y-4">
            @csrf

            <div>
                <label for="limit" class="block text-sm font-medium text-slate-700 mb-1">
                    Nombre de lignes (max 20)
                </label>
                <input
                    type="number"
                    name="limit_override"
                    id="limit"
                    min="1"
                    max="20"
                    value="20"
                    class="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm" />
                <p class="mt-1 text-xs text-slate-500">
                    Combien de paiements bloqués vérifier auprès de leur provider lors de cette exécution — pas un seuil d'ancienneté ; ce seuil est un paramètre fixe de la plateforme.
                </p>
            </div>

            <button
                type="submit"
                @disabled(count($previewPayments) === 0)
                @class([
                    'w-full inline-flex justify-center items-center rounded-md px-4 py-2 text-sm font-medium shadow-sm',
                    'bg-indigo-600 hover:bg-indigo-700 text-white' => count($previewPayments) > 0,
                    'bg-slate-200 text-slate-500 cursor-not-allowed' => count($previewPayments) === 0,
                ])>
                Lancer la réconciliation maintenant
            </button>
        </form>

        @if (! empty($result))
            <div class="mt-6 border-t border-slate-200 pt-4">
                <p class="text-xs uppercase tracking-wider font-semibold text-slate-500 mb-2">Résultat</p>
                <dl class="grid grid-cols-3 gap-3 text-sm">
                    <div class="rounded-md bg-slate-50 border border-slate-200 px-3 py-2">
                        <dt class="text-xs text-slate-500">Vérifiés</dt>
                        <dd class="font-mono text-slate-900 tabular-nums">{{ $result['checked'] ?? 0 }}</dd>
                    </div>
                    <div class="rounded-md bg-emerald-50 border border-emerald-200 px-3 py-2">
                        <dt class="text-xs text-emerald-700">Mis à jour</dt>
                        <dd class="font-mono text-emerald-900 tabular-nums">{{ $result['updated'] ?? 0 }}</dd>
                    </div>
                    <div @class([
                        'rounded-md px-3 py-2',
                        'bg-rose-50 border border-rose-200' => ($result['errors'] ?? 0) > 0,
                        'bg-slate-50 border border-slate-200' => ($result['errors'] ?? 0) === 0,
                    ])>
                        <dt @class([
                            'text-xs',
                            'text-rose-700' => ($result['errors'] ?? 0) > 0,
                            'text-slate-500' => ($result['errors'] ?? 0) === 0,
                        ])>Erreurs</dt>
                        <dd @class([
                            'font-mono tabular-nums',
                            'text-rose-900' => ($result['errors'] ?? 0) > 0,
                            'text-slate-900' => ($result['errors'] ?? 0) === 0,
                        ])>{{ $result['errors'] ?? 0 }}</dd>
                    </div>
                </dl>
            </div>
        @endif
    </div>
</div>
@endsection
