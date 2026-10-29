@extends('layouts.admin')

@section('title', 'Paiements')

@section('content')
<form method="GET" action="{{ route('admin.payments.index') }}" aria-label="Filtrer les paiements" class="bg-white border border-slate-200 rounded-lg p-4 mb-4">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 items-start">
        @php
            $projectOptions = ['' => '(tous les projets)'];
            foreach ($projects as $project) {
                $projectOptions[$project->id] = $project->name;
            }
            $statusOptions = ['' => '(tous)'];
            foreach (['pending', 'processing', 'succeeded', 'failed', 'expired', 'refunded', 'canceled'] as $s) {
                $statusOptions[$s] = ucfirst($s);
            }
        @endphp
        <x-admin.field name="project_id" type="select" label="Projet" :value="$filters['project_id'] ?? ''" :options="$projectOptions" />
        <x-admin.field name="channel" label="Canal" :value="$filters['channel'] ?? ''" class="font-mono" />
        <x-admin.field name="status" type="select" label="Statut" :value="$filters['status'] ?? ''" :options="$statusOptions" />
        <div class="flex items-end gap-2 justify-end sm:col-span-2 lg:col-span-5">
            <a href="{{ route('admin.payments.index') }}" class="text-sm text-slate-600 hover:text-slate-800">Réinitialiser</a>
            <button type="submit" class="inline-flex items-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2 text-base font-medium text-white">Filtrer</button>
        </div>
    </div>
</form>

<x-admin.table
    caption="Paiements"
    :headers="['Créé le', 'ID', 'Projet', 'Canal', 'Statut', 'Montant', 'Devise', 'Actions']"
    :empty="'Aucun paiement ne correspond aux filtres.'">
    <tbody class="divide-y divide-slate-100 bg-white">
    @foreach ($payments as $payment)
        <tr>
            <td class="px-4 py-2 text-sm text-slate-600 whitespace-nowrap tabular-nums">
                {{ $payment->createdAt !== null ? \Illuminate\Support\Carbon::parse($payment->createdAt)->format('d/m/Y H:i') : '—' }}
            </td>
            <td class="px-4 py-2 text-sm font-mono text-slate-700 truncate max-w-[8rem]" title="{{ $payment->id }}">
                {{ substr($payment->id, 0, 8) }}…
            </td>
            <td class="px-4 py-2 text-base text-slate-900 truncate max-w-[10rem]" title="{{ $payment->projectId }}">
                {{ $projectNames[$payment->projectId] ?? substr($payment->projectId, 0, 8).'…' }}
            </td>
            <td class="px-4 py-2 text-sm font-mono text-slate-700">
                {{ $payment->channel }}
            </td>
            <td class="px-4 py-2"><x-admin.status-badge :status="$payment->status" :final="$payment->isFinal" /></td>
            <td class="px-4 py-2 text-base font-mono text-slate-900 tabular-nums">
                {{-- Minor units (G5) → major units, via Money::formattedAmount()
                     — currency-exponent-correct (0 decimals for XAF).
                     Same pattern as reconciliation/new.blade.php. --}}
                {{ \Core\Support\Money\Money::ofMinor($payment->amount, $payment->currency)->groupedAmount() }}
            </td>
            <td class="px-4 py-2 text-base font-mono text-slate-700">
                {{ $payment->currency }}
            </td>
            <td class="px-4 py-2 text-base">
                {{-- Always the payment's OWN project — not the filter's,
                     which can be empty (all-projects view) or, in theory,
                     differ from this row's actual project. --}}
                <a href="{{ route('admin.payments.show', ['payment' => $payment->id, 'project_id' => $payment->projectId]) }}"
                   class="text-indigo-700 hover:text-indigo-900 font-medium">
                    Voir →<span class="sr-only"> le paiement {{ substr($payment->id, 0, 8) }}</span>
                </a>
            </td>
        </tr>
    @endforeach
    </tbody>
</x-admin.table>

<x-admin.pagination :page="$page" :per-page="$perPage" :total="$total" />
@endsection
