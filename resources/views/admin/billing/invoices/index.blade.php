@extends('layouts.admin')

@section('title', 'Factures')

@section('content')
<div class="space-y-6">
    <div class="rounded-lg border border-indigo-100 bg-indigo-50 px-4 py-3.5 text-sm text-indigo-900 leading-relaxed">
        <p class="font-semibold mb-1">Une facture est un document</p>
        <p>
            Une facture porte une créance. À l'émission, elle reçoit son numéro et
            devient figée : ses lignes, ses montants et l'identité du client ne bougent
            plus. Une facture déjà payée ne s'annule pas — elle se rembourse.
        </p>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <form method="GET" action="{{ route('admin.projects.invoices.index', $projectId) }}" class="flex items-center gap-2">
            <select name="status" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
                <option value="">Tous les statuts</option>
                @foreach (['draft' => 'Brouillon', 'open' => 'Ouverte', 'paid' => 'Payée', 'void' => 'Annulée', 'uncollectible' => 'Irrécouvrable'] as $value => $label)
                    <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <button type="submit" class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                Filtrer
            </button>
        </form>
        <a href="{{ route('admin.projects.invoices.new', $projectId) }}"
           class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">
            Nouvelle facture
        </a>
    </div>

    <x-admin.table :headers="['Numéro', 'Client', 'Total', 'Statut', 'Échéance', '']" empty="Aucune facture.">
        @if (count($invoices) > 0)
            <tbody class="divide-y divide-slate-100 bg-white">
            @foreach ($invoices as $invoice)
                <tr>
                    <td class="px-4 py-2 text-sm font-mono text-slate-700">{{ $invoice->number ?? 'brouillon' }}</td>
                    <td class="px-4 py-2 text-sm font-mono text-slate-700">{{ $invoice->customerRef }}</td>
                    <td class="px-4 py-2 text-base text-slate-900 tabular-nums whitespace-nowrap">
                        {{ \Core\Support\Money\Money::ofMinor($invoice->totalMinor, $invoice->currency)->formattedAmount() }} {{ $invoice->currency }}
                    </td>
                    <td class="px-4 py-2 text-sm">
                        @php
                            $labels = [
                                'draft' => 'Brouillon',
                                'open' => 'Ouverte',
                                'paid' => 'Payée',
                                'void' => 'Annulée',
                                'uncollectible' => 'Irrécouvrable',
                            ];
                            $classes = [
                                'draft' => 'bg-slate-100 text-slate-700',
                                'open' => 'bg-indigo-100 text-indigo-700',
                                'paid' => 'bg-emerald-100 text-emerald-700',
                                'void' => 'bg-slate-200 text-slate-700',
                                'uncollectible' => 'bg-rose-100 text-rose-700',
                            ];
                        @endphp
                        <span class="inline-flex rounded-md px-2 py-0.5 text-xs font-medium {{ $classes[$invoice->status] ?? 'bg-slate-100 text-slate-700' }}">
                            {{ $labels[$invoice->status] ?? $invoice->status }}
                        </span>
                    </td>
                    <td class="px-4 py-2 text-sm text-slate-600 tabular-nums whitespace-nowrap">
                        @if ($invoice->dueAt !== null)
                            {{ \Illuminate\Support\Carbon::parse($invoice->dueAt)->format('d/m/Y') }}
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-4 py-2 text-sm text-right">
                        <a href="{{ route('admin.projects.invoices.show', [$projectId, $invoice->id]) }}"
                           class="font-medium text-indigo-600 hover:text-indigo-800">Ouvrir</a>
                    </td>
                </tr>
            @endforeach
            </tbody>
        @endif
    </x-admin.table>

    @if ($nextCursor !== null)
        <a href="{{ route('admin.projects.invoices.index', [$projectId, 'cursor' => $nextCursor]) }}"
           class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Page suivante →</a>
    @endif
</div>
@endsection
