@extends('layouts.admin')

@section('title', 'Opérateurs')

@section('content')
@if (! empty($created))
    <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3">
        <p class="text-base font-semibold text-emerald-800">Opérateur créé : {{ $created['email'] }}</p>
        <p class="mt-1 text-base text-emerald-700">
            Mot de passe à usage unique — affiché uniquement maintenant, jamais stocké ni journalisé :
            <code class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 font-mono text-emerald-900">{{ $created['plaintext_password'] }}</code>
        </p>
    </div>
@endif

<div class="mb-4 flex items-center justify-between gap-4">
    <x-admin.search-box placeholder="Rechercher par email ou nom…" :value="$search" />
    <a href="{{ route('admin.operators.new') }}"
       class="shrink-0 inline-flex items-center gap-2 rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2.5 text-base font-medium text-white shadow-sm">
        Nouvel opérateur
    </a>
</div>

<x-admin.table
    :headers="['Email', 'Nom', 'Rôle', 'MFA', 'Statut', 'Créé le', 'Action']"
    :empty="'Aucun opérateur pour le moment.'">
    <tbody class="divide-y divide-slate-100 bg-white">
    @foreach ($operators as $op)
        <tr x-data="{ confirmRevoke: false }">
            <td class="px-4 py-2 text-base text-slate-900">{{ $op->email }}</td>
            <td class="px-4 py-2 text-base text-slate-700">{{ $op->name }}</td>
            <td class="px-4 py-2 text-sm">
                <span class="inline-flex rounded-md bg-slate-100 px-2 py-0.5 font-mono text-slate-700">
                    {{ $op->role }}
                </span>
            </td>
            <td class="px-4 py-2 text-sm">
                <span @class([
                    'inline-flex rounded-full px-2 py-0.5 font-mono',
                    'bg-emerald-100 text-emerald-700' => $op->mfaEnabled,
                    'bg-slate-100 text-slate-700' => ! $op->mfaEnabled,
                ])>
                    {{ $op->mfaEnabled ? 'Configuré' : 'Non configuré' }}
                </span>
            </td>
            <td class="px-4 py-2 text-sm">
                <span @class([
                    'inline-flex rounded-full px-2 py-0.5 font-mono',
                    'bg-rose-100 text-rose-700' => $op->revoked,
                    'bg-emerald-100 text-emerald-700' => ! $op->revoked,
                ])>
                    {{ $op->revoked ? 'Révoqué' : 'Actif' }}
                </span>
            </td>
            <td class="px-4 py-2 text-sm font-mono text-slate-600 whitespace-nowrap">
                {{ $op->createdAt->format('Y-m-d H:i:s') }}
            </td>
            <td class="px-4 py-2 text-sm space-x-1 whitespace-nowrap">
                <a href="{{ route('admin.operators.edit', ['operatorId' => $op->id]) }}"
                   class="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2 py-1 font-medium text-slate-700 hover:bg-slate-200">
                    Modifier
                </a>
                @unless ($op->revoked)
                    <button
                        @click="confirmRevoke = true"
                        type="button"
                        class="inline-flex items-center gap-1 rounded-md bg-rose-50 px-2 py-1 font-medium text-rose-700 hover:bg-rose-100">
                        Révoquer
                    </button>
                    <form
                        x-show="confirmRevoke"
                        x-cloak
                        @submit="confirmRevoke = false"
                        method="POST"
                        action="{{ route('admin.operators.revoke.web', ['operatorId' => $op->id]) }}"
                        class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50"
                        @click.self="confirmRevoke = false">
                        @csrf
                        <div class="bg-white rounded-lg shadow-xl p-6 max-w-sm mx-4" @click.stop>
                            <p class="font-semibold text-slate-900 mb-2">Révoquer {{ $op->email }} ?</p>
                            <p class="text-sm text-slate-600 mb-4">
                                Ses jetons d'accès seront rejetés dès sa
                                prochaine requête. Cette action est
                                irréversible depuis cet écran.
                            </p>
                            <div class="flex justify-end gap-2">
                                <button type="button" @click="confirmRevoke = false"
                                        class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">Annuler</button>
                                <button type="submit"
                                        class="rounded-md bg-rose-600 hover:bg-rose-700 px-3 py-1.5 text-sm font-medium text-white">Confirmer</button>
                            </div>
                        </div>
                    </form>
                @endunless
            </td>
        </tr>
    @endforeach
    </tbody>
</x-admin.table>

<x-admin.pagination :page="$page" :per-page="$perPage" :total="$total" />
@endsection
