@extends('layouts.admin')

@section('title', 'Encaissements hors ligne')

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <a href="{{ route('admin.projects.invoices.index', $projectId) }}"
           class="text-sm font-medium text-slate-600 hover:text-slate-900">← Toutes les factures</a>
        <div class="flex items-center gap-2 text-sm">
            @php
                $filters = [
                    'pending' => 'À valider',
                    'approved' => 'Comptabilisés',
                    'rejected' => 'Refusés',
                ];
            @endphp
            @foreach ($filters as $value => $label)
                <a href="{{ route('admin.projects.receipts.index', [$projectId, 'status' => $value]) }}"
                   class="rounded-md px-2 py-1 text-xs font-medium {{ $status === $value ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>
    </div>

    <div class="rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-900">
        Un encaissement hors ligne est un acte comptable : il porte une méthode, une référence
        et un montant, et il est inscrit au grand livre du client. Au-dessus du seuil du
        projet, il attend une validation explicite avant de compter.
    </div>

    @if ($errors->any())
        <div class="rounded-md bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <x-admin.table :headers="['Référence', 'Montant', 'Méthode', 'Reçu le', 'État', 'Validation']" empty="Aucun encaissement hors ligne.">
        @if (count($receipts) > 0)
            <tbody class="divide-y divide-slate-100 bg-white">
            @foreach ($receipts as $receipt)
                @php
                    $labels = [
                        'pending' => 'En attente',
                        'approved' => 'Comptabilisé',
                        'rejected' => 'Refusé',
                    ];
                    $classes = [
                        'pending' => 'bg-amber-100 text-amber-700',
                        'approved' => 'bg-emerald-100 text-emerald-700',
                        'rejected' => 'bg-rose-100 text-rose-700',
                    ];
                    $methods = [
                        'bank_transfer' => 'Virement',
                        'cash' => 'Espèces',
                        'cheque' => 'Chèque',
                        'other' => 'Autre',
                    ];
                @endphp
                <tr>
                    <td class="px-4 py-2 text-sm font-mono text-slate-700">{{ $receipt->reference }}</td>
                    <td class="px-4 py-2 text-sm text-slate-900 tabular-nums whitespace-nowrap">
                        {{ \Core\Support\Money\Money::ofMinor($receipt->amountMinor, $receipt->currency)->formattedAmount() }}
                        {{ $receipt->currency }}
                    </td>
                    <td class="px-4 py-2 text-sm text-slate-700">{{ $methods[$receipt->method] ?? $receipt->method }}</td>
                    <td class="px-4 py-2 text-sm text-slate-700 tabular-nums whitespace-nowrap">
                        {{ \Illuminate\Support\Carbon::parse($receipt->receivedAt)->format('d/m/Y') }}
                    </td>
                    <td class="px-4 py-2 text-sm whitespace-nowrap">
                        <span class="inline-flex rounded-md px-2 py-0.5 text-xs font-medium {{ $classes[$receipt->status] ?? 'bg-slate-100 text-slate-700' }}">
                            {{ $labels[$receipt->status] ?? $receipt->status }}
                        </span>
                    </td>
                    <td class="px-4 py-2 text-sm whitespace-nowrap">
                        @if ($receipt->status === 'pending')
                            <div class="flex items-center gap-2">
                                <form method="POST" action="{{ route('admin.projects.receipts.approve.web', [$projectId, $receipt->id]) }}">
                                    @csrf
                                    <input type="hidden" name="decision" value="approve">
                                    <button type="submit" class="rounded-md bg-emerald-700 px-2 py-1 text-xs font-semibold text-white hover:bg-emerald-800">
                                        Valider
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('admin.projects.receipts.approve.web', [$projectId, $receipt->id]) }}">
                                    @csrf
                                    <input type="hidden" name="decision" value="reject">
                                    <button type="submit" class="rounded-md bg-slate-700 px-2 py-1 text-xs font-semibold text-white hover:bg-slate-800">
                                        Refuser
                                    </button>
                                </form>
                            </div>
                        @else
                            <span class="text-xs text-slate-400">—</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        @endif
    </x-admin.table>

    @if ($nextCursor !== null)
        <div class="text-right">
            <a href="{{ route('admin.projects.receipts.index', [$projectId, 'status' => $status, 'cursor' => $nextCursor]) }}"
               class="text-sm font-medium text-indigo-700 hover:text-indigo-900">Page suivante →</a>
        </div>
    @endif
</div>
@endsection
