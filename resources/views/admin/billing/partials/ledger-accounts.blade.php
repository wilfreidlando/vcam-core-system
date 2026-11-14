{{--
    Les comptes du projet — TOUS les comptes, y compris ceux qui n'ont
    encore jamais bougé (ADR-0125 §6). La balance, elle, ne liste que les
    comptes qui ont bougé.

    Variables : $rows (les lignes préparées par le contrôleur), $projectId,
    $selected (le compte dont les mouvements sont ouverts, ou null).
--}}
@php
    $montant = fn (int $minor, string $currency): string => \Core\Support\Money\Money::ofMinor($minor, $currency)->groupedAmount();
@endphp
<x-admin.table :caption="\App\Http\View\LedgerAccountLabels::ACCOUNTS_HEADING" :headers="\App\Http\View\LedgerAccountLabels::ACCOUNTS_HEADERS" :empty="\App\Http\View\LedgerAccountLabels::NO_ACCOUNT">
    @if (count($rows) > 0)
        <tbody class="divide-y divide-slate-100 bg-white">
            @foreach ($rows as $row)
                @php($ouvert = ($selected ?? null) !== null && $selected->kind === $row['kind'] && $selected->reference === $row['reference'] && $selected->currency === $row['currency'])
                <tr @class(['bg-indigo-50' => $ouvert])>
                    <td class="px-4 py-2">
                        <a href="{{ route('admin.projects.ledger.accounts', ['projectId' => $projectId, 'kind' => $row['kind'], 'reference' => $row['reference'], 'currency' => $row['currency']]) }}"
                           class="font-medium text-indigo-700 hover:text-indigo-900">{{ $row['nature'] }}</a>
                        <span class="mt-0.5 block text-xs text-slate-500">{{ $row['owner'] }}</span>
                    </td>
                    <td class="px-4 py-2 text-sm text-slate-700">{{ $row['explanation'] }}</td>
                    <td class="px-4 py-2 font-mono text-xs text-slate-600">{{ $row['currency'] }}</td>
                    <td class="px-4 py-2 text-right tabular-nums">{{ $montant($row['debitMinor'], $row['currency']) }}</td>
                    <td class="px-4 py-2 text-right tabular-nums">{{ $montant($row['creditMinor'], $row['currency']) }}</td>
                    <td class="px-4 py-2 text-right tabular-nums font-medium">{{ $montant($row['netMinor'], $row['currency']) }}</td>
                </tr>
            @endforeach
        </tbody>
    @endif
</x-admin.table>
