@extends('layouts.admin')

@section('title', 'Créances')

@section('content')
@php
    use Core\Support\Money\Money;

    $money = fn (array $figures): string => $figures === [] ? '—' : collect($figures)
        ->map(fn (int $minor, string $currency): string => Money::ofMinor($minor, $currency)->groupedAmount().' '.$currency)
        ->implode(' · ');
@endphp
<div class="space-y-6">
    <p class="text-sm text-slate-600">
        {{ $project->name }} — ce qui est dû, ce qui est en retard et ce qui a été abandonné.
        Les montants sont lus au grand livre, pas dans un compteur de facture.
    </p>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <x-admin.stat label="Créances" :text="$money($owed)" hint="ce qu'on vous doit" tone="amber"/>
        <x-admin.stat label="En retard" :text="$money($late)" hint="échue, non encaissée" tone="rose"/>
        <x-admin.stat label="Irrécouvrables" :text="$money($badDebt)" hint="acte de l'exploitant (ADR-0094 §12)"/>
    </div>

    <section aria-labelledby="open-invoices">
        <h2 id="open-invoices" class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Factures ouvertes</h2>
        <x-admin.table caption="Factures ouvertes et restant à encaisser" :headers="['Numéro', 'Client', 'Échéance', 'Total', 'Reste dû', 'Retard']" empty="Aucune facture ouverte.">
            @if (count($open) > 0)
                <tbody class="divide-y divide-slate-100 bg-white">
                @foreach ($open as $row)
                    @php $invoice = $row['invoice']; @endphp
                    <tr>
                        <td class="px-4 py-2 text-sm font-mono text-slate-700">
                            <a href="{{ route('admin.projects.invoices.show', ['projectId' => $projectId, 'invoiceId' => $invoice->id]) }}" class="text-indigo-700 hover:text-indigo-900">{{ $invoice->number ?? 'brouillon' }}</a>
                        </td>
                        <td class="px-4 py-2 text-sm font-mono text-slate-700">{{ $invoice->customerRef }}</td>
                        <td class="px-4 py-2 text-sm text-slate-700">{{ $invoice->dueAt ?? '—' }}</td>
                        <td class="px-4 py-2 text-base text-slate-900 tabular-nums whitespace-nowrap">
                            {{ Money::ofMinor($invoice->totalMinor, $invoice->currency)->groupedAmount() }} {{ $invoice->currency }}
                        </td>
                        <td class="px-4 py-2 text-base text-slate-900 tabular-nums whitespace-nowrap">
                            {{ Money::ofMinor($row['remaining'], $invoice->currency)->groupedAmount() }} {{ $invoice->currency }}
                        </td>
                        <td class="px-4 py-2 text-sm tabular-nums">
                            @if ($row['daysLate'] === null)
                                <span class="text-slate-500">—</span>
                            @else
                                <span @class(['font-semibold', 'text-rose-700' => $row['daysLate'] > 30])>{{ $row['daysLate'] }} j</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            @endif
        </x-admin.table>
    </section>

    <section aria-labelledby="written-off">
        <h2 id="written-off" class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Créances irrécouvrables</h2>
        <x-admin.table caption="Créances abandonnées et la date de l'acte" :headers="['Numéro', 'Client', 'Abandonnée le', 'Montant']" empty="Aucune créance abandonnée.">
            @if (count($writtenOff) > 0)
                <tbody class="divide-y divide-slate-100 bg-white">
                @foreach ($writtenOff as $row)
                    @php $invoice = $row['invoice']; @endphp
                    <tr>
                        <td class="px-4 py-2 text-sm font-mono text-slate-700">
                            <a href="{{ route('admin.projects.invoices.show', ['projectId' => $projectId, 'invoiceId' => $invoice->id]) }}" class="text-indigo-700 hover:text-indigo-900">{{ $invoice->number ?? 'brouillon' }}</a>
                        </td>
                        <td class="px-4 py-2 text-sm font-mono text-slate-700">{{ $invoice->customerRef }}</td>
                        <td class="px-4 py-2 text-sm text-slate-700">{{ $row['lostAt'] ?? '—' }}</td>
                        <td class="px-4 py-2 text-base text-slate-900 tabular-nums whitespace-nowrap">
                            {{ Money::ofMinor($row['remaining'], $invoice->currency)->groupedAmount() }} {{ $invoice->currency }}
                        </td>
                    </tr>
                @endforeach
                </tbody>
            @endif
        </x-admin.table>
    </section>
</div>
@endsection
