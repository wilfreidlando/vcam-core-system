@extends('layouts.admin')

@section('title', 'Import de clients')

@section('content')
@php
    $columns = $columns ?? \App\Http\View\CustomerImportTemplate::columns();

    $summary = '';
    if ($report !== null) {
        $created = (int) $report['created'];
        $known = (int) $report['alreadyKnown'];
        $refused = (int) $report['refused'];

        $summary = sprintf(
            '%d client%s créé%s, %d client%s déjà connu%s, %d ligne%s refusée%s.',
            $created,
            $created > 1 ? 's' : '',
            $created > 1 ? 's' : '',
            $known,
            $known > 1 ? 's' : '',
            $known > 1 ? 's' : '',
            $refused,
            $refused > 1 ? 's' : '',
            $refused > 1 ? 's' : '',
        );
    }
@endphp
<div class="space-y-6">
    <div class="rounded-lg border border-indigo-100 bg-indigo-50 px-4 py-3.5 text-sm text-indigo-900 leading-relaxed">
        <p class="font-semibold mb-1">Les clients qu'une application avait déjà</p>
        <p>
            L'application exporte ses clients dans un fichier, vous le déposez ici, le Core les crée
            et les pousse. Un client déjà connu est mis à jour, jamais dupliqué. Chaque ligne refusée
            est comptée dans le rapport, avec sa raison.
        </p>
    </div>

    <div class="max-w-2xl rounded-lg border border-slate-200 bg-white p-6">
        <h2 class="text-base font-semibold text-slate-900">Le modèle du fichier</h2>
        <p class="mt-1 text-sm text-slate-700">
            Le fichier doit porter {{ count($columns) }} colonne{{ count($columns) > 1 ? 's' : '' }}, dans cet ordre. Téléchargez le modèle : il porte
            l'en-tête et une ligne d'exemple, et il s'importe tel quel.
        </p>

        <dl class="mt-4 space-y-3">
            @foreach ($columns as $column)
                <div>
                    <dt class="text-sm font-medium text-slate-900">{{ $column['label'] }}</dt>
                    <dd class="text-sm text-slate-700">{{ $column['explanation'] }}</dd>
                </div>
            @endforeach
        </dl>

        <a href="{{ route('admin.projects.customers.import.template', $projectId) }}"
           class="mt-5 inline-block rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
            Télécharger le modèle
        </a>
    </div>

    <div class="max-w-2xl rounded-lg border border-slate-200 bg-white p-6">
        <form method="POST"
              action="{{ route('admin.projects.customers.import', $projectId) }}"
              enctype="multipart/form-data"
              class="space-y-4">
            @csrf

            <x-admin.field name="file" type="custom" label="Fichier à importer" required
                           hint="Un fichier CSV (virgule comme séparateur, encodage UTF-8) dont la première ligne est l'en-tête du modèle.">
                <input type="file" id="field-file" name="file" accept=".csv,text/csv" required
                       class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm"
                       @if ($errors->has('file')) aria-invalid="true" @endif
                       aria-describedby="field-file-hint{{ $errors->has('file') ? ' field-file-error' : '' }}">
            </x-admin.field>

            @if ($pattern !== null)
                <p class="text-sm text-slate-700">
                    Les références doivent respecter le format en vigueur :
                    <span class="font-mono text-slate-900">{{ $pattern }}</span>
                    — {{ \App\Http\View\TenantSchemaLabels::pattern($pattern) }}.
                </p>
            @endif

            <div class="flex items-center gap-3 pt-2">
                <button type="submit"
                        class="rounded-md bg-indigo-700 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-800">
                    Importer les clients
                </button>
                <a href="{{ route('admin.projects.customers.index', $projectId) }}"
                   class="inline-block px-2.5 py-1.5 text-sm font-medium text-slate-600 hover:text-slate-900">Retour aux clients</a>
            </div>
        </form>
    </div>

    @if ($report !== null)
        <div class="space-y-4 rounded-lg border border-slate-200 bg-white p-5">
            <div>
                <h2 class="text-base font-semibold text-slate-900">Rapport de l'import</h2>
                <p class="mt-1 text-sm text-slate-700">{{ $summary }}</p>
            </div>

            <x-admin.table caption="Lignes refusées" :headers="['Ligne', 'Référence', 'Motif']" empty="Aucune ligne refusée.">
                @if (count($report['refusals']) > 0)
                    <tbody class="divide-y divide-slate-100 bg-white">
                    @foreach ($report['refusals'] as $refusal)
                        <tr>
                            <td class="px-4 py-2 text-sm tabular-nums">{{ $refusal['line'] }}</td>
                            <td class="px-4 py-2 text-sm font-mono text-slate-700">{{ $refusal['reference'] }}</td>
                            <td class="px-4 py-2 text-sm text-slate-700">{{ $refusal['reason'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                @endif
            </x-admin.table>

            @if (count($report['known']) > 0)
                <div>
                    <h3 class="text-sm font-semibold text-slate-900">Clients déjà connus</h3>
                    <p class="text-sm text-slate-600">
                        Ces clients existaient déjà : ils ont été mis à jour, jamais dupliqués.
                    </p>
                </div>

                <x-admin.table caption="Clients déjà connus" :headers="['Ligne', 'Référence']" empty="Aucun client déjà connu.">
                    <tbody class="divide-y divide-slate-100 bg-white">
                    @foreach ($report['known'] as $client)
                        <tr>
                            <td class="px-4 py-2 text-sm tabular-nums">{{ $client['line'] }}</td>
                            <td class="px-4 py-2 text-sm font-mono text-slate-700">{{ $client['reference'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </x-admin.table>
            @endif
        </div>
    @endif
</div>
@endsection
