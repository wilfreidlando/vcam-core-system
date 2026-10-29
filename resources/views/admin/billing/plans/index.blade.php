@extends('layouts.admin')

@section('title', 'Plans')

@section('content')
<div class="space-y-6">
    <div class="rounded-lg border border-indigo-100 bg-indigo-50 px-4 py-3.5 text-sm text-indigo-900 leading-relaxed">
        <p class="font-semibold mb-1">Un plan est stable, ses versions portent les termes</p>
        <p>
            Le prix, l'intervalle, l'essai et les fonctionnalités vivent dans une
            <strong>version</strong>, pas dans le plan. Une version publiée est
            <strong>figée</strong> : changer un prix, c'est publier une nouvelle version —
            les abonnements déjà signés gardent leurs conditions.
        </p>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('admin.projects.plans.new', $projectId) }}"
           class="rounded-md bg-indigo-700 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-800">
            Nouveau plan
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
                <x-admin.confirm-dialog
                    :title="'Archiver le plan '.$plan->code.' ?'"
                    message="Le plan n'est plus proposé aux nouvelles souscriptions. Les abonnements en cours gardent leurs conditions."
                    :action="route('admin.projects.plans.archive.web', [$projectId, $plan->code])"
                    confirm="Archiver">
                    <x-slot:trigger>
                        <button type="button" class="rounded-md px-2.5 py-1.5 text-sm font-medium text-rose-700 hover:bg-rose-50 hover:text-rose-900">Archiver</button>
                    </x-slot:trigger>
                </x-admin.confirm-dialog>
            </div>

            <x-admin.table :caption="'Versions du plan '.$plan->code" :headers="['Version', 'Prix', 'Période', 'Essai', 'Grâce', 'Statut', '']" empty="Aucune version.">
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
        </div>
    @empty
        <x-admin.table caption="Plans du projet" :headers="['Plan']" empty="Aucun plan." />
    @endforelse

    @include('admin.billing.partials.cursor-pager', ['routeName' => 'admin.projects.plans.index', 'routeParams' => [$projectId], 'nextCursor' => $nextCursor])
</div>
@endsection
