@extends('layouts.admin')

@section('title', 'Offres')

@section('content')
<div class="space-y-6">
    <div class="rounded-lg border border-indigo-100 bg-indigo-50 px-4 py-3.5 text-sm text-indigo-900 leading-relaxed">
        <p class="font-semibold mb-1">Une offre est stable, ses versions portent les conditions</p>
        <p>
            Le prix, l'intervalle, l'essai et les fonctionnalités vivent dans une
            <strong>version</strong>, pas dans l'offre. Une version publiée est
            <strong>figée</strong> : changer un prix, c'est publier une nouvelle version —
            les abonnements déjà signés gardent leurs conditions.
        </p>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('admin.projects.plans.new', $projectId) }}"
           class="rounded-md bg-indigo-700 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-800">
            Nouvelle offre
        </a>
    </div>

    @forelse ($plans as $plan)
        <div class="rounded-lg border border-slate-200 bg-white">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
                <div>
                    <span class="font-mono text-sm text-slate-500">{{ $plan->code }}</span>
                    <span class="ml-2 text-base font-semibold text-slate-900">{{ $plan->name }}</span>
                    @if ($plan->visibility === 'private')
                        <span class="ml-2 rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">privé</span>
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <a href="{{ route('admin.projects.plans.versions.new', [$projectId, $plan->code]) }}"
                       class="rounded-md px-2.5 py-1.5 text-sm font-medium text-indigo-700 hover:bg-indigo-50 hover:text-indigo-900">+ Ajouter une version</a>
                <x-admin.confirm-dialog
                    :title="'Archiver l\'offre '.$plan->code.' ?'"
                    message="L'offre n'est plus proposée aux nouvelles souscriptions. Les abonnements en cours gardent leurs conditions."
                    :action="route('admin.projects.plans.archive.web', [$projectId, $plan->code])"
                    confirm="Archiver">
                    <x-slot:trigger>
                        <button type="button" class="rounded-md px-2.5 py-1.5 text-sm font-medium text-rose-700 hover:bg-rose-50 hover:text-rose-900">Archiver</button>
                    </x-slot:trigger>
                </x-admin.confirm-dialog>
                </div>
            </div>

            <x-admin.table :caption="'Versions de l\'offre '.$plan->code" :headers="['Version', 'Prix', 'Période', 'Essai', 'Grâce', 'Statut', '']" empty="Aucune version.">
                @if (count($plan->versions) > 0)
                    <tbody class="divide-y divide-slate-100 bg-white">
                    @foreach ($plan->versions as $version)
                        <tr>
                            <td class="px-4 py-2 text-sm tabular-nums text-slate-700">{{ $version->version }}</td>
                            <td class="px-4 py-2 text-base text-slate-900 tabular-nums whitespace-nowrap">
                                {{ \Core\Support\Money\Money::ofMinor($version->priceMinor, $version->currency)->groupedAmount() }} {{ $version->currency }}
                            </td>
                            <td class="px-4 py-2 text-sm text-slate-700">
                                {{ $version->intervalCount }} {{ $version->intervalUnit }}{{ $version->intervalCount > 1 ? 's' : '' }}
                            </td>
                            <td class="px-4 py-2 text-sm text-slate-700 tabular-nums">{{ $version->trialDays }} j</td>
                            <td class="px-4 py-2 text-sm text-slate-700 tabular-nums">
                                {{ $version->graceDays }} j{{ $version->graceAccess ? ' (accès maintenu)' : '' }}
                            </td>
                            <td class="px-4 py-2 text-sm">
                                @php
                                    $labels = ['draft' => 'Brouillon', 'published' => 'Publiée', 'retired' => 'Retirée'];
                                    $classes = [
                                        'draft' => 'bg-slate-100 text-slate-700',
                                        'published' => 'bg-emerald-100 text-emerald-700',
                                        'retired' => 'bg-amber-100 text-amber-700',
                                    ];
                                @endphp
                                <span class="inline-flex rounded-md px-2 py-0.5 text-xs font-medium {{ $classes[$version->status] ?? 'bg-slate-100 text-slate-700' }}">
                                    {{ $labels[$version->status] ?? $version->status }}
                                </span>
                            </td>
                            <td class="px-4 py-2 text-sm text-right">
                                @if ($version->status === 'draft')
                                    <a href="{{ route('admin.projects.plans.versions.edit', [$projectId, $plan->code, $version->version]) }}"
                                       class="mr-2 inline-block rounded-md px-2.5 py-1.5 text-sm font-medium text-indigo-700 hover:bg-indigo-50 hover:text-indigo-900">Modifier</a>
                                    <x-admin.confirm-dialog
                                        :title="'Publier la version '.$version->version.' de '.$plan->code.' ?'"
                                        message="Une version publiée est figée : prix, période et essai ne changeront plus. Corriger un tarif demandera une nouvelle version."
                                        :action="route('admin.projects.plans.versions.publish.web', [$projectId, $plan->code, $version->version])"
                                        confirm="Publier"
                                        variant="primary">
                                        <x-slot:trigger>
                                            <button type="button" class="rounded-md px-2.5 py-1.5 text-sm font-medium text-indigo-700 hover:bg-indigo-50 hover:text-indigo-900">Publier</button>
                                        </x-slot:trigger>
                                    </x-admin.confirm-dialog>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                @endif
            </x-admin.table>

            {{-- Ce que l'offre compte : au-delà du prix, c'est ce que le client
                 consomme et ce qui peut lui être facturé. Un projet qui ne compte
                 rien le dit ici, noir sur blanc. --}}
            <div class="border-t border-slate-100 px-4 py-4">
                <h3 class="text-sm font-semibold text-slate-900">Ce que cette offre compte</h3>
                @if (count(array_filter($counting[$plan->code] ?? [], static fn (array $row): bool => $row['isLimit'])) === 0)
                    <p class="mt-1 text-sm text-slate-600">
                        <strong>Cette offre ne compte rien</strong> : aucune limite d'usage n'est appliquée. Tout
                        ce que le client consomme est sans limite, et rien ne lui est facturé au-delà du prix
                        de la version.
                    </p>
                @else
                    <x-admin.table :caption="'Ce que compte l\'offre '.$plan->code"
                                    :headers="['Version', 'Élément', 'Type', 'Compteur', 'Limite', 'Remise', 'Dépassement', 'Alerte']"
                                    empty="Cette offre ne déclare aucune fonctionnalité.">
                        @foreach ($counting[$plan->code] ?? [] as $row)
                            <tr>
                                <td class="px-4 py-2 text-sm tabular-nums text-slate-700">{{ $row['version'] }}</td>
                                <td class="px-4 py-2 text-sm font-medium text-slate-900">{{ $row['element'] }}</td>
                                <td class="px-4 py-2 text-sm text-slate-700">{{ $row['kind'] }}</td>
                                <td class="px-4 py-2 text-sm font-mono text-slate-700">{{ $row['counter'] ?? '—' }}</td>
                                <td class="px-4 py-2 text-sm text-slate-700 tabular-nums">
                                    @if (! $row['isLimit'])
                                        —
                                    @elseif ($row['limit'] === null)
                                        illimité
                                    @else
                                        {{ number_format($row['limit'], 0, ',', "\u{202F}") }}
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-sm text-slate-700">{{ $row['renews'] ?? '—' }}</td>
                                <td class="px-4 py-2 text-sm text-slate-700">{{ $row['over'] ?? '—' }}</td>
                                <td class="px-4 py-2 text-sm text-slate-700 tabular-nums">
                                    @if ($row['alert'] === null)
                                        —
                                    @else
                                        {{ rtrim(rtrim(number_format($row['alert'] / 100, 1, ',', "\u{202F}"), '0'), ',') }}&nbsp;%
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </x-admin.table>
                @endif

                <p class="mt-3 text-sm text-slate-600">
                    Les <strong>compteurs</strong> que le projet compte se déclarent dans
                    <a href="{{ route('admin.projects.commerce-settings', $projectId) }}" class="font-medium text-indigo-700 hover:text-indigo-900">Réglages du commerce</a>
                    (rubrique « Limites d'usage ») ; les <strong>packs de rechargement</strong> que le client peut
                    acheter pour aller au-delà de sa limite se déclarent dans la même page (rubrique
                    « Packs de rechargement »).
                </p>
            </div>
        </div>
    @empty
        <x-admin.table caption="Offres du projet" :headers="['Offre']" empty="Aucune offre." />
    @endforelse

    @include('admin.billing.partials.cursor-pager', ['routeName' => 'admin.projects.plans.index', 'routeParams' => [$projectId], 'nextCursor' => $nextCursor])
</div>
@endsection
