@extends('layouts.admin')

@section('title', 'Lancer une réconciliation')

@section('content')
<div class="space-y-6">

    {{-- Action errors (batch limit validation, a single reconcile
         failing against its provider, …) — `withErrors()` from either
         controller action lands here. Genuinely necessary: none of
         these error keys ('limit_override', 'payment') map to a
         standing, always-visible form field the way a login field does,
         so without this banner they were silently dropped (review
         finding: adding a per-row action without visible failure
         feedback would just trade one confusing page for another). --}}
    @if ($errors->any())
        <div class="rounded-md bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    {{-- Explanation of what "reconciliation" concretely does — review
         finding: "la réconciliation ici fait quoi concrètement ?". This
         used to be un-said anywhere on the page; an operator had only a
         limit field and a button with no idea what pressing it triggers. --}}
    <div class="rounded-lg border border-indigo-100 bg-indigo-50 px-4 py-3.5 text-sm text-indigo-900 leading-relaxed">
        <p class="font-semibold mb-1">Que fait concrètement une réconciliation ?</p>
        <p>
            Pour chaque paiement resté bloqué en <span class="font-mono">pending</span>/<span class="font-mono">processing</span>,
            le Core appelle directement le provider de paiement (vérification de
            statut) pour connaître son statut réel. Si ce statut diffère de
            celui enregistré ici, le Core se met à jour exactement comme si le
            webhook du provider venait d'arriver — y compris le crédit du
            wallet si le paiement le prévoyait. C'est utile quand un webhook a
            probablement été perdu : le paiement est peut-être déjà terminé
            côté provider sans que le Core l'ait jamais su.
        </p>
    </div>

    {{-- Preview: exactly which payments a run would touch, before the
         operator commits to running it (review finding: reconciliation
         used to run blind, with no visibility into what it would
         check). Each row now also carries its own action — review
         finding: "je dois pouvoir réconcilier une transaction
         sélectionnée … tu n'as pas mis d'action dessus". --}}
    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200">
            <h2 class="font-semibold text-slate-900">
                @if (count($previewPayments) > 0)
                    Ces {{ count($previewPayments) }} paiement{{ count($previewPayments) === 1 ? '' : 's' }} {{ count($previewPayments) === 1 ? 'sera vérifié' : 'seront vérifiés' }} au lancement de la réconciliation groupée
                @else
                    Aucun paiement n'a besoin de réconciliation pour le moment
                @endif
            </h2>
            <p class="text-sm text-slate-500 mt-0.5">
                Réconciliez un paiement précis avec le bouton de sa ligne, ou
                tous ceux-ci d'un coup avec la réconciliation groupée ci-dessous.
            </p>
        </div>

        @if (count($previewPayments) > 0)
            <x-admin.table :headers="['ID', 'Projet', 'Canal', 'Statut', 'Montant', 'Bloqué depuis', 'Action']" :empty="''">
                <tbody class="divide-y divide-slate-100 bg-white">
                @foreach ($previewPayments as $payment)
                    <tr x-data="{ confirmReconcile: false }">
                        <td class="px-4 py-2 text-sm font-mono text-slate-700" title="{{ $payment->id }}">
                            {{ substr($payment->id, 0, 8) }}…
                        </td>
                        <td class="px-4 py-2 text-base text-slate-900 truncate max-w-[10rem]" title="{{ $payment->projectId }}">
                            {{ $projectNames[$payment->projectId] ?? substr($payment->projectId, 0, 8).'…' }}
                        </td>
                        <td class="px-4 py-2 text-base text-slate-700">{{ $payment->channel }}</td>
                        <td class="px-4 py-2 text-sm">
                            <span class="inline-flex rounded-md bg-amber-100 px-2 py-0.5 font-mono text-amber-700">
                                {{ $payment->status }}
                            </span>
                        </td>
                        {{-- Minor units -> major units, via
                             Money::formattedAmount() — currency-exponent-correct. --}}
                        <td class="px-4 py-2 text-base font-mono text-slate-900 tabular-nums text-right">
                            {{ \Core\Support\Money\Money::ofMinor($payment->amount, $payment->currency)->formattedAmount() }} {{ $payment->currency }}
                        </td>
                        {{-- How long this payment has actually sat
                             untouched — the detail an operator needs to
                             judge whether the run is worth trusting, not
                             just which rows it would touch. --}}
                        <td class="px-4 py-2 text-sm text-slate-500 whitespace-nowrap" title="{{ $payment->updatedAt?->format('Y-m-d H:i:s') ?? '' }}">
                            {{-- Payment::$updatedAt is a native DateTimeImmutable
                                 (Domain layer stays framework-free, G3b) — wrap
                                 in Carbon here, in the view, just for the
                                 human-readable diff. --}}
                            {{ $payment->updatedAt !== null ? \Illuminate\Support\Carbon::instance($payment->updatedAt)->diffForHumans() : '—' }}
                        </td>
                        <td class="px-4 py-2 text-sm whitespace-nowrap">
                            <button
                                @click="confirmReconcile = true"
                                type="button"
                                class="inline-flex items-center gap-1 rounded-md bg-indigo-50 px-2 py-1 font-medium text-indigo-700 hover:bg-indigo-100">
                                Réconcilier
                            </button>
                            <form
                                x-show="confirmReconcile"
                                x-cloak
                                @submit="confirmReconcile = false"
                                method="POST"
                                action="{{ route('admin.reconciliation.run-one', ['payment' => $payment->id]) }}"
                                class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50"
                                @click.self="confirmReconcile = false">
                                @csrf
                                <div class="bg-white rounded-lg shadow-xl p-6 max-w-sm mx-4" @click.stop>
                                    <p class="font-semibold text-slate-900 mb-2">Réconcilier ce paiement ?</p>
                                    <p class="text-sm text-slate-600 mb-4">
                                        Vérifie son statut réel directement auprès du
                                        provider et met à jour le Core si le statut a
                                        changé.
                                    </p>
                                    <div class="flex justify-end gap-2">
                                        <button type="button" @click="confirmReconcile = false"
                                                class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">Annuler</button>
                                        <button type="submit"
                                                class="rounded-md bg-indigo-600 hover:bg-indigo-700 px-3 py-1.5 text-sm font-medium text-white">Confirmer</button>
                                    </div>
                                </div>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </x-admin.table>
        @endif
    </div>

    <div class="bg-white border border-slate-200 rounded-lg p-6">

        <h2 class="text-lg font-semibold text-slate-900 mb-1">Réconciliation groupée</h2>
        <p class="text-base text-slate-600 mb-4">
            Exécute de façon synchrone, sur tous les paiements bloqués listés
            ci-dessus (dans la limite ci-dessous), le même traitement que la
            tâche planifiée
            (<code class="text-sm bg-slate-100 px-1.5 py-0.5 rounded">admin:reconcile-stuck-payments</code>),
            avec un plafond strict de 20 lignes par appel pour garder une
            réponse HTTP rapide.
        </p>

        <form method="POST" action="{{ route('admin.reconciliation.run') }}" class="space-y-4">
            @csrf

            <div>
                <label for="limit" class="block text-base font-medium text-slate-700 mb-1">
                    Nombre de lignes (max 20)
                </label>
                <input
                    type="number"
                    name="limit_override"
                    id="limit"
                    min="1"
                    max="20"
                    value="20"
                    class="block w-full rounded-md border border-slate-300 px-4 py-2.5 text-base shadow-sm" />
                <p class="mt-1 text-sm text-slate-500">
                    Combien de paiements bloqués vérifier auprès de leur provider lors de cette exécution — pas un seuil d'ancienneté ; ce seuil est un paramètre fixe de la plateforme.
                </p>
            </div>

            <button
                type="submit"
                @disabled(count($previewPayments) === 0)
                @class([
                    'w-full inline-flex justify-center items-center rounded-md px-4 py-2.5 text-base font-medium shadow-sm',
                    'bg-indigo-600 hover:bg-indigo-700 text-white' => count($previewPayments) > 0,
                    'bg-slate-200 text-slate-500 cursor-not-allowed' => count($previewPayments) === 0,
                ])>
                Lancer la réconciliation groupée maintenant
            </button>
        </form>

        @if (! empty($result))
            <div class="mt-6 border-t border-slate-200 pt-4">
                <p class="text-sm uppercase tracking-wider font-semibold text-slate-500 mb-2">Résultat</p>
                <dl class="grid grid-cols-3 gap-3 text-base">
                    <div class="rounded-md bg-slate-50 border border-slate-200 px-3 py-2">
                        <dt class="text-sm text-slate-500">Vérifiés</dt>
                        <dd class="font-mono text-slate-900 tabular-nums">{{ $result['checked'] ?? 0 }}</dd>
                    </div>
                    <div class="rounded-md bg-emerald-50 border border-emerald-200 px-3 py-2">
                        <dt class="text-sm text-emerald-700">Mis à jour</dt>
                        <dd class="font-mono text-emerald-900 tabular-nums">{{ $result['updated'] ?? 0 }}</dd>
                    </div>
                    <div @class([
                        'rounded-md px-3 py-2',
                        'bg-rose-50 border border-rose-200' => ($result['errors'] ?? 0) > 0,
                        'bg-slate-50 border border-slate-200' => ($result['errors'] ?? 0) === 0,
                    ])>
                        <dt @class([
                            'text-sm',
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
