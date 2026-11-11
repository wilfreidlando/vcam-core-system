@extends('layouts.admin')

@section('title', 'Forme du client')

@section('content')
<div class="space-y-6">
    @if ($proposal === null)
        <div class="rounded-lg border border-slate-200 bg-white p-5 space-y-3">
            <h2 class="text-base font-semibold text-slate-900">Rien à décider pour l'instant</h2>
            <p class="text-sm text-slate-700">
                Cette application n'a rien proposé à lire. La forme du client ne change que lorsqu'une
                application propose une déclaration et que vous l'acceptez.
            </p>
            <a href="{{ route('admin.projects.customers.index', $projectId) }}"
               class="inline-block px-2.5 py-1.5 text-sm font-medium text-indigo-700 hover:text-indigo-900">← Retour aux clients</a>
        </div>
    @else
        <div class="rounded-lg border border-indigo-100 bg-indigo-50 px-4 py-3.5 text-sm text-indigo-900 leading-relaxed">
            <p class="font-semibold mb-1">L'application a proposé une forme pour ses clients</p>
            <p>
                Lisez-la avant de l'accepter : c'est elle qui dira quelles valeurs le client porte et
                quel format sa référence doit respecter.
            </p>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-5 space-y-2">
            <h2 class="text-base font-semibold text-slate-900">La référence</h2>
            <p class="text-sm text-slate-700">
                Le code que l'application utilise :
                <span class="font-mono text-slate-900">{{ $proposal->uniqueKeyPattern }}</span>
                — {{ \App\Http\View\TenantSchemaLabels::pattern($proposal->uniqueKeyPattern) }}.
            </p>
            <p class="text-sm text-slate-700">
                Version proposée : <span class="tabular-nums">{{ $proposal->version }}</span>.
            </p>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white">
            <div class="border-b border-slate-100 px-5 py-3">
                <h2 class="text-base font-semibold text-slate-900">Les champs annoncés</h2>
            </div>

            <x-admin.table caption="Champs annoncés par l'application" :headers="['Champ', 'Type', 'Obligatoire', 'Origine', 'Longueur maximale']" empty="Aucun champ annoncé.">
                @foreach ($proposal->fields as $field)
                    <tr>
                        <td class="px-4 py-2 text-sm font-medium text-slate-900">{{ $field->label }}</td>
                        <td class="px-4 py-2 text-sm text-slate-700">{{ \App\Http\View\TenantSchemaLabels::type($field->type) }}</td>
                        <td class="px-4 py-2 text-sm text-slate-700">{{ $field->required ? 'Oui' : 'Non' }}</td>
                        <td class="px-4 py-2 text-sm text-slate-700">{{ \App\Http\View\TenantSchemaLabels::source($field->source) }}</td>
                        <td class="px-4 py-2 text-sm text-slate-700 tabular-nums">{{ $field->maxLength === null ? '—' : $field->maxLength }}</td>
                    </tr>
                @endforeach
            </x-admin.table>

            <div class="border-t border-slate-100 px-5 py-4 text-sm text-slate-700">
                <p>
                    Les valeurs que l'opérateur saisira dans ces champs sont conservées en clair, comme
                    le nom du client. Elles ne doivent donc <strong>pas</strong> porter de donnée
                    personnelle : ni pièce d'identité, ni adresse, ni numéro de téléphone.
                </p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <form method="POST" action="{{ route('admin.projects.customers.schema.accept', [$projectId, $proposal->id]) }}">
                @csrf
                <button type="submit"
                        class="rounded-md bg-indigo-700 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-800">
                    Accepter la déclaration
                </button>
            </form>

            <form method="POST" action="{{ route('admin.projects.customers.schema.reject', [$projectId, $proposal->id]) }}">
                @csrf
                <button type="submit"
                        class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    Refuser la déclaration
                </button>
            </form>

            <a href="{{ route('admin.projects.customers.index', $projectId) }}"
               class="inline-block px-2.5 py-1.5 text-sm font-medium text-slate-600 hover:text-slate-900">← Retour aux clients</a>
        </div>
    @endif
</div>
@endsection
