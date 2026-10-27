@extends('layouts.admin')

@section('title', 'Clients')

@section('content')
<div class="space-y-6">
    <div class="rounded-lg border border-indigo-100 bg-indigo-50 px-4 py-3.5 text-sm text-indigo-900 leading-relaxed">
        <p class="font-semibold mb-1">Le client est déclaré par le projet</p>
        <p>
            Un client est l'identité minimale d'un payeur : la référence que le projet lui
            connaît, un nom affiché et — facultativement — un contact. Le contact est
            <strong>chiffré au repos</strong> : il n'est affiché que masqué, jamais en clair.
            Un client suspendu perd ses droits mais reste dû ; un client effacé perd son
            identité, pas son historique.
        </p>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <form method="GET" action="{{ route('admin.projects.customers.index', $projectId) }}" class="flex items-center gap-2">
            <input type="search" name="search" value="{{ $search }}" placeholder="Référence, nom, contact…"
                   class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">
            <button type="submit" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">
                Rechercher
            </button>
        </form>

        <a href="{{ route('admin.projects.customers.new', $projectId) }}"
           class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">
            Nouveau client
        </a>
    </div>

    <x-admin.table :headers="['Référence', 'Nom', 'Contact', 'Statut', 'Version', '']" empty="Aucun client déclaré.">
        @if (count($customers) > 0)
            <tbody class="divide-y divide-slate-100 bg-white">
            @foreach ($customers as $customer)
                <tr>
                    <td class="px-4 py-2 text-sm font-mono text-slate-700">{{ $customer->externalRef }}</td>
                    <td class="px-4 py-2 text-base text-slate-900">{{ $customer->displayName }}</td>
                    <td class="px-4 py-2 text-sm text-slate-600">
                        @if ($customer->email !== null)
                            {{ \Core\Support\Privacy\PersonalDataMask::email($customer->email) }}
                        @else
                            <span class="text-slate-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-2 text-sm">
                        @php
                            $labels = ['active' => 'Actif', 'suspended' => 'Suspendu', 'erased' => 'Effacé'];
                            $classes = [
                                'active' => 'bg-emerald-100 text-emerald-700',
                                'suspended' => 'bg-amber-100 text-amber-700',
                                'erased' => 'bg-slate-100 text-slate-700',
                            ];
                        @endphp
                        <span class="inline-flex rounded-md px-2 py-0.5 text-xs font-medium {{ $classes[$customer->status] ?? 'bg-slate-100 text-slate-700' }}">
                            {{ $labels[$customer->status] ?? $customer->status }}
                        </span>
                    </td>
                    <td class="px-4 py-2 text-sm tabular-nums text-slate-600">{{ $customer->version }}</td>
                    <td class="px-4 py-2 text-sm text-right whitespace-nowrap">
                        <a href="{{ route('admin.projects.customers.edit', [$projectId, $customer->externalRef]) }}"
                           class="font-medium text-indigo-600 hover:text-indigo-800">Modifier</a>

                        @if ($customer->status === 'active')
                            <form method="POST" class="inline"
                                  action="{{ route('admin.projects.customers.suspend.web', [$projectId, $customer->externalRef]) }}">
                                @csrf
                                <button type="submit" class="ml-3 font-medium text-amber-700 hover:text-amber-900">Suspendre</button>
                            </form>
                        @elseif ($customer->status === 'suspended')
                            <form method="POST" class="inline"
                                  action="{{ route('admin.projects.customers.reactivate.web', [$projectId, $customer->externalRef]) }}">
                                @csrf
                                <button type="submit" class="ml-3 font-medium text-emerald-700 hover:text-emerald-900">Réactiver</button>
                            </form>
                        @endif

                        @if ($customer->status !== 'erased')
                            <form method="POST" class="inline"
                                  action="{{ route('admin.projects.customers.erase.web', [$projectId, $customer->externalRef]) }}"
                                  onsubmit="return confirm('Effacer l\'identité de ce client ? Ses factures restent dues.')">
                                @csrf
                                <button type="submit" class="ml-3 font-medium text-rose-700 hover:text-rose-900">Effacer</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        @endif
    </x-admin.table>

    @if ($nextCursor !== null)
        <a href="{{ route('admin.projects.customers.index', [$projectId, 'cursor' => $nextCursor]) }}"
           class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Page suivante →</a>
    @endif
</div>
@endsection
