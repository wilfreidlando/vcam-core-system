@extends('layouts.admin')

@section('title', 'Opérateurs')

@section('content')
@php
    $roleLabels = [
        'platform_admin' => 'Administrateur de plateforme',
        'finance_operator' => 'Opérateur financier',
        'support_operator' => 'Opérateur support',
        'provider_manager' => 'Responsable des prestataires',
        'read_only_auditor' => 'Auditeur (lecture seule)',
    ];
@endphp
{{-- Les erreurs de validation sont affichées par le layout (role="alert") :
     un second bloc ici les annoncerait deux fois. --}}

{{-- H1 — le jeton est rendu dans la réponse du POST (`$created`), jamais flashé en session. --}}
@if (! empty($created))
    <x-admin.alert variant="success" title="Invitation créée : {{ $created['email'] }}">
        <p class="mt-1 text-base">
            Jeton d'activation à usage unique — affiché uniquement maintenant, jamais stocké ni journalisé :
            <code class="ml-1 break-all rounded bg-emerald-100 px-1.5 py-0.5 font-mono text-emerald-900">{{ $created['token'] }}</code>
        </p>
        <p class="mt-1 text-sm">
            Valable jusqu'au {{ $created['expires_at'] }}. Transmettez-le à l'invité : c'est
            <strong>lui</strong> qui choisit son mot de passe et enrôle son MFA (aucun mot de passe
            n'est choisi par le système). Il l'active sur
            <a class="font-mono underline" href="{{ route('admin.invitation.show') }}">{{ route('admin.invitation.show') }}</a>.
        </p>
    </x-admin.alert>
@endif

<div class="mb-4 flex items-center justify-between gap-4">
    <x-admin.search-box placeholder="Rechercher par email ou nom…" :value="$search" />
    <a href="{{ route('admin.operators.new') }}"
       class="shrink-0 inline-flex items-center gap-2 rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2.5 text-base font-medium text-white shadow-sm">
        Nouvel opérateur
    </a>
</div>

<x-admin.table
    caption="Opérateurs de la console"
    :headers="['Email', 'Nom', 'Rôle', 'MFA', 'Statut', 'Créé le', '']"
    :empty="'Aucun opérateur pour le moment.'">
    <tbody class="divide-y divide-slate-100 bg-white">
    @foreach ($operators as $op)
        <tr>
            <td class="px-4 py-2 text-base text-slate-900">{{ $op->email }}</td>
            <td class="px-4 py-2 text-base text-slate-700">{{ $op->name }}</td>
            <td class="px-4 py-2 text-sm">
                <span class="inline-flex rounded-md bg-slate-100 px-2 py-0.5 text-slate-700" title="{{ $op->role }}">
                    {{ $roleLabels[$op->role] ?? $op->role }}
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
            <td class="px-4 py-2 text-sm whitespace-nowrap">
                <div class="flex items-center gap-1">
                    <a href="{{ route('admin.operators.edit', ['operatorId' => $op->id]) }}"
                       class="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2.5 py-1.5 font-medium text-slate-700 hover:bg-slate-200">
                        Modifier
                        <span class="sr-only">{{ $op->email }}</span>
                    </a>
                    @unless ($op->revoked)
                        {{-- C1/C2/C14/C15 — modale accessible : role="dialog", aria-modal,
                             aria-labelledby, piège et restitution du focus, déclencheur
                             aria-haspopup / aria-expanded. --}}
                        <x-admin.confirm-dialog
                            :title="'Révoquer '.$op->email.' ?'"
                            message="Ses jetons d'accès seront rejetés dès sa prochaine requête. Cette action est irréversible depuis cet écran."
                            :action="route('admin.operators.revoke.web', ['operatorId' => $op->id])"
                            confirm="Révoquer">
                            <x-slot:trigger>
                                <button type="button" class="inline-flex items-center gap-1 rounded-md bg-rose-50 px-2.5 py-1.5 font-medium text-rose-700 hover:bg-rose-100">
                                    Révoquer
                                    <span class="sr-only">{{ $op->email }}</span>
                                </button>
                            </x-slot:trigger>
                        </x-admin.confirm-dialog>
                    @endunless
                </div>
            </td>
        </tr>
    @endforeach
    </tbody>
</x-admin.table>

<x-admin.pagination :page="$page" :per-page="$perPage" :total="$total" />
@endsection
