@extends('layouts.admin')

@section('title', "Exceptions d'argent")

@section('content')
<div class="space-y-6">
    <div class="rounded-lg border border-indigo-100 bg-indigo-50 px-4 py-3.5 text-sm text-indigo-900 leading-relaxed">
        <p class="font-semibold mb-1">De quoi s'agit-il ?</p>
        <p>
            Une exception est de l'argent que la plateforme n'a pas pu imputer automatiquement
            (par exemple : le prestataire confirme un autre montant que celui demandé), ou un
            contrôle comptable en échec. L'argent concerné est mis en attente au compte des
            exceptions du grand livre ; rien n'est crédité tant qu'une personne n'a pas décidé.
            Au-delà du seuil de validation, la décision doit être confirmée par une seconde personne.
        </p>
    </div>

    @php
        $filters = ['' => 'Toutes', 'open' => 'Ouvertes', 'awaiting_approval' => 'À valider', 'resolved' => 'Résolues'];
        $statusLabels = ['open' => 'Ouverte', 'awaiting_approval' => 'À valider', 'resolved' => 'Résolue'];
        $statusClasses = [
            'open' => 'bg-rose-100 text-rose-700',
            'awaiting_approval' => 'bg-amber-100 text-amber-700',
            'resolved' => 'bg-emerald-100 text-emerald-700',
        ];
        $severityClasses = [
            'critical' => 'bg-rose-600 text-white',
            'high' => 'bg-rose-100 text-rose-700',
            'medium' => 'bg-amber-100 text-amber-700',
            'low' => 'bg-slate-100 text-slate-700',
        ];
        $severityLabels = ['critical' => 'Critique', 'high' => 'Haute', 'medium' => 'Moyenne', 'low' => 'Basse'];
    @endphp

    <nav class="flex flex-wrap gap-2" aria-label="Filtrer par statut">
        @foreach ($filters as $value => $label)
            <a href="{{ route('admin.money-exceptions.index', $value === '' ? [] : ['status' => $value]) }}"
               class="rounded-md px-3 py-1.5 text-sm font-medium {{ ($status ?? '') === $value ? 'bg-slate-900 text-white' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-50' }}">
                {{ $label }}
            </a>
        @endforeach
    </nav>

    <x-admin.table :headers="['Statut', 'Gravité', 'Type', 'Projet', 'Montant', 'En attente au grand livre', 'Ouverte le', '']" empty="Aucune exception d'argent.">
        @if (count($exceptions) > 0)
            <tbody class="divide-y divide-slate-100 bg-white">
            @foreach ($exceptions as $exception)
                <tr>
                    <td class="px-4 py-2 text-sm">
                        <span class="inline-flex rounded-md px-2 py-0.5 text-xs font-medium {{ $statusClasses[$exception->status] ?? 'bg-slate-100 text-slate-700' }}">{{ $statusLabels[$exception->status] ?? $exception->status }}</span>
                    </td>
                    <td class="px-4 py-2 text-sm">
                        <span class="inline-flex rounded-md px-2 py-0.5 text-xs font-medium {{ $severityClasses[$exception->severity] ?? '' }}">{{ $severityLabels[$exception->severity] ?? $exception->severity }}</span>
                    </td>
                    <td class="px-4 py-2 text-base text-slate-900">{{ $exception->kindLabel }}</td>
                    <td class="px-4 py-2 text-base text-slate-700 truncate max-w-[12rem]" title="{{ $exception->projectId }}">
                        {{ $projectNames[$exception->projectId] ?? substr($exception->projectId, 0, 8).'…' }}
                    </td>
                    <td class="px-4 py-2 text-base text-slate-900 tabular-nums whitespace-nowrap">
                        {{ \Core\Support\Money\Money::ofMinor($exception->amountMinor, $exception->currency)->formattedAmount() }} {{ $exception->currency }}
                    </td>
                    <td class="px-4 py-2 text-base text-slate-700 tabular-nums whitespace-nowrap">
                        {{ \Core\Support\Money\Money::ofMinor($exception->bookedAmountMinor, $exception->currency)->formattedAmount() }} {{ $exception->currency }}
                    </td>
                    <td class="px-4 py-2 text-sm text-slate-600 tabular-nums whitespace-nowrap">
                        {{ \Illuminate\Support\Carbon::parse($exception->createdAt)->format('d/m/Y H:i') }}
                    </td>
                    <td class="px-4 py-2 text-sm text-right">
                        <a href="{{ route('admin.money-exceptions.show', $exception->id) }}" class="text-indigo-600 hover:text-indigo-800 font-medium">
                            {{ $exception->status === 'resolved' ? 'Voir' : 'Traiter' }}
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
