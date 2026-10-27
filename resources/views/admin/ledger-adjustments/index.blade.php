@extends('layouts.admin')

@section('title', "Corrections d'exploitation")

@section('content')
<div class="space-y-6">
    <div class="rounded-lg border border-indigo-100 bg-indigo-50 px-4 py-3.5 text-sm text-indigo-900 leading-relaxed">
        <p class="font-semibold mb-1">De quoi s'agit-il ?</p>
        <p>
            Une correction d'exploitation est un mouvement comptable décidé en console :
            un montant, un compte source, un compte destination, un motif. Elle n'écrit jamais
            dans une écriture existante — elle produit une nouvelle transaction équilibrée
            au grand livre, et seulement après la validation d'une seconde personne.
        </p>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <nav class="flex flex-wrap gap-2" aria-label="Filtrer par statut">
            @php
                $filters = ['' => 'Toutes', 'proposed' => 'À valider', 'approved' => 'Validées', 'rejected' => 'Refusées'];
                $statusLabels = ['proposed' => 'À valider', 'approved' => 'Validée', 'rejected' => 'Refusée'];
                $statusClasses = [
                    'proposed' => 'bg-amber-100 text-amber-700',
                    'approved' => 'bg-emerald-100 text-emerald-700',
                    'rejected' => 'bg-slate-100 text-slate-700',
                ];
            @endphp
            @foreach ($filters as $value => $label)
                <a href="{{ route('admin.ledger-adjustments.index', $value === '' ? [] : ['status' => $value]) }}"
                   class="rounded-md px-3 py-1.5 text-sm font-medium {{ ($status ?? '') === $value ? 'bg-slate-900 text-white' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-50' }}">
                    {{ $label }}
                </a>
            @endforeach
        </nav>

        @if ($canWrite)
            <a href="{{ route('admin.ledger-adjustments.new') }}"
               class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">
                Proposer une correction
            </a>
        @endif
    </div>

    <x-admin.table :headers="['Statut', 'Projet', 'Source', 'Destination', 'Montant', 'Motif', 'Proposée le', '']" empty="Aucune correction d'exploitation.">
        @if (count($adjustments) > 0)
            <tbody class="divide-y divide-slate-100 bg-white">
            @foreach ($adjustments as $adjustment)
                <tr>
                    <td class="px-4 py-2 text-sm">
                        <span class="inline-flex rounded-md px-2 py-0.5 text-xs font-medium {{ $statusClasses[$adjustment->status] ?? 'bg-slate-100 text-slate-700' }}">{{ $statusLabels[$adjustment->status] ?? $adjustment->status }}</span>
                    </td>
                    <td class="px-4 py-2 text-base text-slate-700 truncate max-w-[12rem]" title="{{ $adjustment->projectId }}">
                        {{ $projectNames[$adjustment->projectId] ?? substr($adjustment->projectId, 0, 8).'…' }}
                    </td>
                    <td class="px-4 py-2 text-sm font-mono text-slate-700">
                        {{ $adjustment->sourceKind }}@if ($adjustment->sourceReference !== ''):{{ $adjustment->sourceReference }}@endif
                    </td>
                    <td class="px-4 py-2 text-sm font-mono text-slate-700">
                        {{ $adjustment->destinationKind }}@if ($adjustment->destinationReference !== ''):{{ $adjustment->destinationReference }}@endif
                    </td>
                    <td class="px-4 py-2 text-base text-slate-900 tabular-nums whitespace-nowrap">
                        {{ \Core\Support\Money\Money::ofMinor($adjustment->amountMinor, $adjustment->currency)->formattedAmount() }} {{ $adjustment->currency }}
                    </td>
                    <td class="px-4 py-2 text-sm text-slate-700 max-w-[20rem] truncate" title="{{ $adjustment->reason }}">
                        {{ $adjustment->reason }}
                    </td>
                    <td class="px-4 py-2 text-sm text-slate-600 tabular-nums whitespace-nowrap">
                        {{ \Illuminate\Support\Carbon::parse($adjustment->createdAt)->format('d/m/Y H:i') }}
                    </td>
                    <td class="px-4 py-2 text-sm text-right">
                        <a href="{{ route('admin.ledger-adjustments.show', $adjustment->id) }}" class="text-indigo-600 hover:text-indigo-800 font-medium">
                            {{ $adjustment->status === 'proposed' ? 'Traiter' : 'Voir' }}
                        </a>
                    </td>
                </tr>
            @endforeach
            </tbody>
        @endif
    </x-admin.table>

    <x-admin.pagination :page="$page" :per-page="$perPage" :total="$total" />
</div>
@endsection
