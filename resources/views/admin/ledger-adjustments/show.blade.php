@extends('layouts.admin')

@section('title', "Correction d'exploitation")

@section('content')
<div class="space-y-6">
    @php
        $field = 'text-sm uppercase tracking-wider font-semibold text-slate-600';
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

    {{-- Statut et erreurs : rendus par le layout (role="status" / role="alert"). --}}

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
                    <a href="{{ route('admin.projects.show', ['projectId' => $adjustment->projectId]) }}" class="text-indigo-700 hover:text-indigo-900 font-medium">{{ $projectName }}</a>
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
                    <p class="text-slate-600">—</p>
                @endif
            </div>
            <div class="md:col-span-2">
                <p class="{{ $field }}">Transaction contrer-passée</p>
                @if ($adjustment->reversesTransactionId !== null)
                    <p class="font-mono text-slate-700 break-all">{{ $adjustment->reversesTransactionId }}</p>
                @else
                    <p class="text-slate-600">—</p>
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
                    {{-- #26 : écrire au grand livre / refuser sont irréversibles → confirmation en dialogue. --}}
                    <x-admin.confirm-dialog
                        title="Valider et écrire au grand livre ?"
                        message="La correction sera écrite au grand livre. Cette action est définitive."
                        :action="route('admin.ledger-adjustments.approve', $adjustment->id)"
                        confirm="Valider et écrire"
                        variant="primary">
                        <x-slot:trigger>
                            <button type="button" class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Valider et écrire au grand livre</button>
                        </x-slot:trigger>
                    </x-admin.confirm-dialog>
                    <x-admin.confirm-dialog
                        title="Refuser cette correction ?"
                        message="La correction proposée est écartée : rien n'est écrit au grand livre."
                        :action="route('admin.ledger-adjustments.reject', $adjustment->id)"
                        confirm="Refuser">
                        <x-slot:trigger>
                            <button type="button" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Refuser</button>
                        </x-slot:trigger>
                    </x-admin.confirm-dialog>
                </div>
            @endif
        </div>
    @endif

    <p><a href="{{ route('admin.ledger-adjustments.index') }}" class="text-sm text-indigo-700 hover:text-indigo-900">← Toutes les corrections</a></p>
</div>
@endsection
