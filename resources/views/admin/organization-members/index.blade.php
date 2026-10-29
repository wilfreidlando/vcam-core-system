@extends('layouts.admin')

@section('title', 'Membres — '.$organization->name)

@section('content')
{{-- ADR-0069 §4 — the activation token is shown ONCE, right after the
     invitation is created. It is never stored in clear and never
     retrievable again afterwards. `$invitation` is rendered in the POST
     response itself. H3 — la région est `role="status"` (x-admin.alert). --}}
@if (! empty($invitation))
    <x-admin.alert variant="success" title="Invitation créée pour {{ $invitation['email'] }} ({{ $invitation['role'] }})">
        <p class="mt-1 text-base">
            Jeton d'activation — affiché uniquement maintenant, jamais stocké en clair ni relu :
            <code class="ml-1 break-all rounded bg-emerald-100 px-1.5 py-0.5 font-mono text-emerald-900">{{ $invitation['token'] }}</code>
        </p>
        <p class="mt-1 text-sm">
            Expire le {{ $invitation['expires_at'] }} (72 h). Transmettez-le à l'invité : il choisit
            son mot de passe (ou confirme celui de son compte existant) et enrôle son MFA à l'activation, sur
            <a class="font-mono underline" href="{{ route('admin.invitation.show') }}">{{ route('admin.invitation.show') }}</a>.
        </p>
    </x-admin.alert>
@endif

{{-- Les erreurs de validation sont affichées par le layout (role="alert"). --}}

<div class="mb-4 flex items-center justify-between gap-4">
    <p class="text-base text-slate-600">
        {{ count($members) }} membre{{ count($members) > 1 ? 's' : '' }} — les membres sont gérés par
        un <code class="font-mono">org_owner</code> ou un <code class="font-mono">org_admin</code> ; seul un
        <code class="font-mono">org_owner</code> attribue ce rôle (ADR-0069).
    </p>
    @if ($canManage)
    <a href="{{ route('admin.organizations.members.invite', ['organizationId' => $organization->id]) }}"
       class="shrink-0 inline-flex items-center gap-2 rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2.5 text-base font-medium text-white shadow-sm">
        + Inviter un membre
    </a>
    @endif
</div>

<x-admin.table
    caption="Membres de {{ $organization->name }}"
    :headers="['Email', 'Nom', 'Rôle', 'Arrivé le', '']"
    :empty="'Aucun membre pour le moment.'">
    <tbody class="divide-y divide-slate-100 bg-white">
    @foreach ($members as $member)
        <tr>
            <td class="px-4 py-2 text-base text-slate-900">{{ $member->email }}</td>
            <td class="px-4 py-2 text-base text-slate-700">{{ $member->name }}</td>
            <td class="px-4 py-2 text-sm">
                @if ($canManage)
                {{-- B10/B11 — le sélecteur a une étiquette, et le changement n'est plus
                     soumis à la volée (WCAG 3.2.2) : un bouton « Appliquer » explicite. --}}
                <form method="POST" action="{{ route('admin.organizations.members.role.web', ['organizationId' => $organization->id, 'operatorId' => $member->operatorId]) }}"
                      class="flex items-center gap-2">
                    @csrf
                    @method('PUT')
                    <label for="role-{{ $member->operatorId }}" class="sr-only">Rôle de {{ $member->email }}</label>
                    <select id="role-{{ $member->operatorId }}" name="role"
                            class="rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm font-mono text-slate-700">
                        @foreach ($roles as $role)
                            <option value="{{ $role }}" @selected($role === $member->role)>{{ $role }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">
                        Appliquer
                        <span class="sr-only">le rôle à {{ $member->email }}</span>
                    </button>
                </form>
                @else
                <span class="font-mono text-slate-700">{{ $member->role }}</span>
                @endif
            </td>
            <td class="px-4 py-2 text-sm font-mono text-slate-600 whitespace-nowrap">
                {{ $member->joinedAt->format('Y-m-d H:i:s') }}
            </td>
            <td class="px-4 py-2 text-sm whitespace-nowrap">
                @if ($canManage)
                {{-- C10-C12 — modale accessible (piège et restitution du focus, ARIA). --}}
                <x-admin.confirm-dialog
                    :title="'Révoquer '.$member->email.' ?'"
                    message="Son appartenance à cette organisation prend fin ; le compte console lui-même reste actif. Le dernier Owner ne peut pas être révoqué."
                    :action="route('admin.organizations.members.revoke.web', ['organizationId' => $organization->id, 'operatorId' => $member->operatorId])"
                    confirm="Révoquer">
                    <x-slot:trigger>
                        <button type="button" class="inline-flex items-center gap-1 rounded-md bg-rose-50 px-2.5 py-1.5 font-medium text-rose-700 hover:bg-rose-100">
                            Révoquer
                            <span class="sr-only">{{ $member->email }}</span>
                        </button>
                    </x-slot:trigger>
                </x-admin.confirm-dialog>
                @endif
            </td>
        </tr>
    @endforeach
    </tbody>
</x-admin.table>
@endsection
