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
           class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">
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
                <form method="POST" action="{{ route('admin.projects.plans.archive.web', [$projectId, $plan->code]) }}">
                    @csrf
                    <button type="submit" class="text-sm font-medium text-rose-700 hover:text-rose-900">Archiver</button>
                </form>
            </div>

            <x-admin.table :headers="['Version', 'Prix', 'Période', 'Essai', 'Grâce', 'Statut', '']" empty="Aucune version.">
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
                                    <form method="POST" action="{{ route('admin.projects.plans.versions.publish.web', [$projectId, $plan->code, $version->version]) }}">
                                        @csrf
                                        <button type="submit" class="font-medium text-indigo-600 hover:text-indigo-800">Publier</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                @endif
            </x-admin.table>
        </div>
    @empty
        <x-admin.table :headers="['Plan']" empty="Aucun plan." />
    @endforelse

    @if ($nextCursor !== null)
        <a href="{{ route('admin.projects.plans.index', [$projectId, 'cursor' => $nextCursor]) }}"
           class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Page suivante →</a>
    @endif
</div>
@endsection
