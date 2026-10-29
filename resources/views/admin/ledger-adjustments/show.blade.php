@extends('layouts.admin')

@section('title', "Correction d'exploitation")

@section('content')
<div class="space-y-6">
    @php
        $field = 'text-sm uppercase tracking-wider font-semibold text-slate-500';
        $fmt = static fn (?string $iso): string => $iso !== null ? \Illuminate\Support\Carbon::parse($iso)->format('d/m/Y H:i:s') : '—';
        $money = static fn (int $minor) => \Core\Support\Money\Money::ofMinor($minor, $adjustment->currency)->groupedAmount().' '.$adjustment->currency;
        $statusLabels = ['proposed' => 'En attente de validation', 'approved' => 'Validée', 'rejected' => 'Refusée'];
        $statusClasses = [
            'proposed' => 'bg-amber-100 text-amber-700',
            'approved' => 'bg-emerald-100 text-emerald-700',
            'rejected' => 'bg-slate-100 text-slate-700',
        ];
        $account = static fn (string $kind, string $reference): string => $reference === '' ? $kind : $kind.':'.$reference;
    @endphp

    @if (session('status'))
        <div class="rounded-md bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="rounded-md bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <div class="bg-white border border-slate-200 rounded-lg p-6">
        <div class="flex flex-wrap items-center gap-3 mb-2">
            <span class="text-2xl font-semibold text-slate-900">{{ $money($adjustment->amountMinor) }}</span>
            <span class="inline-flex rounded-md px-2 py-0.5 text-xs font-medium {{ $statusClasses[$adjustment->status] ?? 'bg-slate-100 text-slate-700' }}">{{ $statusLabels[$adjustment->status] ?? $adjustment->status }}</span>
        </div>
        <p class="text-base text-slate-700 mb-5">{{ $adjustment->reason }}</p>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-x-6 gap-y-4 text-base">
            <div>
                <p class="{{ $field }}">Compte débité</p>
                <p class="font-mono text-slate-900 break-all">{{ $account($adjustment->sourceKind, $adjustment->sourceReference) }}</p>
            </div>
            <div>
                <p class="{{ $field }}">Compte crédité</p>
                <p class="font-mono text-slate-900 break-all">{{ $account($adjustment->destinationKind, $adjustment->destinationReference) }}</p>
            </div>
            <div>
                <p class="{{ $field }}">Projet</p>
                @if ($projectName !== null)
                    <a href="{{ route('admin.projects.show', ['projectId' => $adjustment->projectId]) }}" class="text-indigo-600 hover:text-indigo-800 font-medium">{{ $projectName }}</a>
                @else
                    <p class="font-mono text-slate-700 break-all">{{ $adjustment->projectId }}</p>
                @endif
            </div>
            <div>
                <p class="{{ $field }}">Proposée par</p>
                <p class="font-mono text-slate-800 break-all">{{ $adjustment->proposedBy }}</p>
            </div>
            <div>
                <p class="{{ $field }}">Validée par</p>
                <p class="font-mono text-slate-800 break-all">{{ $adjustment->approvedBy ?? '—' }}</p>
            </div>
            <div>
                <p class="{{ $field }}">Proposée le</p>
                <p class="text-slate-800 tabular-nums">{{ $fmt($adjustment->createdAt) }}</p>
            </div>
            <div>
                <p class="{{ $field }}">Validée le</p>
                <p class="text-slate-800 tabular-nums">{{ $fmt($adjustment->approvedAt) }}</p>
            </div>
            <div class="md:col-span-2">
                <p class="{{ $field }}">Transaction au grand livre</p>
                @if ($adjustment->ledgerTransactionId !== null)
                    <p class="font-mono text-slate-700 break-all">{{ $adjustment->ledgerTransactionId }}</p>
                @else
                    <p class="text-slate-500">—</p>
                @endif
            </div>
            <div class="md:col-span-2">
                <p class="{{ $field }}">Transaction contrer-passée</p>
                @if ($adjustment->reversesTransactionId !== null)
                    <p class="font-mono text-slate-700 break-all">{{ $adjustment->reversesTransactionId }}</p>
                @else
                    <p class="text-slate-500">—</p>
                @endif
            </div>
        </div>
    </div>

    @if ($canWrite && $adjustment->status === 'proposed')
        <div class="bg-white border border-amber-200 rounded-lg p-6 space-y-3">
            <h2 class="font-semibold text-slate-900">Validation par une seconde personne</h2>
            @if ($adjustment->proposedBy === $operatorId)
                <p class="text-sm text-slate-600">Vous avez proposé cette correction : une autre personne doit la valider.</p>
            @else
                <div class="flex flex-wrap gap-3">
                    <form method="POST" action="{{ route('admin.ledger-adjustments.approve', $adjustment->id) }}">
                        @csrf
                        <button type="submit" class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Valider et écrire au grand livre</button>
                    </form>
                    <form method="POST" action="{{ route('admin.ledger-adjustments.reject', $adjustment->id) }}">
                        @csrf
                        <button type="submit" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Refuser</button>
                    </form>
                </div>
            @endif
        </div>
    @endif

    <p><a href="{{ route('admin.ledger-adjustments.index') }}" class="text-sm text-indigo-600 hover:text-indigo-800">← Toutes les corrections</a></p>
</div>
@endsection
