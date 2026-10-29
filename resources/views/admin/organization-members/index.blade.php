@extends('layouts.admin')

@section('title', 'Membres — '.$organization->name)

@section('content')
{{-- ADR-0069 §4 — the activation token is shown ONCE, right after the
     invitation is created. It is never stored in clear and never
     retrievable again afterwards. --}}
@if (! empty($invitation))
    <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3">
        <p class="text-base font-semibold text-emerald-800">
            Invitation créée pour {{ $invitation['email'] }} ({{ $invitation['role'] }})
        </p>
        <p class="mt-1 text-base text-emerald-700">
            Jeton d'activation — affiché uniquement maintenant, jamais stocké en clair ni relu :
            <code class="ml-1 break-all rounded bg-emerald-100 px-1.5 py-0.5 font-mono text-emerald-900">{{ $invitation['token'] }}</code>
        </p>
        <p class="mt-1 text-sm text-emerald-700">
            Expire le {{ $invitation['expires_at'] }} (72 h). Transmettez-le à l'invité : il choisit
            son mot de passe (ou confirme celui de son compte existant) et enrôle son MFA à l'activation, sur
            <a class="font-mono underline" href="{{ route('admin.invitation.show') }}">{{ route('admin.invitation.show') }}</a>.
        </p>
    </div>
@endif

@if ($errors->any())
    <div class="mb-4 rounded-md border border-rose-200 bg-rose-50 px-4 py-3">
        @foreach ($errors->all() as $error)
            <p class="text-base text-rose-800">{{ $error }}</p>
        @endforeach
    </div>
@endif

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
    :headers="['Email', 'Nom', 'Rôle', 'Arrivé le', 'Action']"
    :empty="'Aucun membre pour le moment.'">
    <tbody class="divide-y divide-slate-100 bg-white">
    @foreach ($members as $member)
        <tr x-data="{ confirmRevoke: false }">
            <td class="px-4 py-2 text-base text-slate-900">{{ $member->email }}</td>
            <td class="px-4 py-2 text-base text-slate-700">{{ $member->name }}</td>
            <td class="px-4 py-2 text-sm">
                @if ($canManage)
                <form method="POST" action="{{ route('admin.organizations.members.role.web', ['organizationId' => $organization->id, 'operatorId' => $member->operatorId]) }}"
                      class="flex items-center gap-2">
                    @csrf
                    @method('PUT')
                    <select name="role"
                            onchange="this.form.submit()"
                            class="rounded-md border border-slate-300 px-2 py-1 text-sm font-mono text-slate-700">
                        @foreach ($roles as $role)
                            <option value="{{ $role }}" @selected($role === $member->role)>{{ $role }}</option>
                        @endforeach
                    </select>
                </form>
                @else
                <span class="font-mono text-slate-700">{{ $member->role }}</span>
                @endif
            </td>
            <td class="px-4 py-2 text-sm font-mono text-slate-600 whitespace-nowrap">
                {{ $member->joinedAt->format('Y-m-d H:i:s') }}
            </td>
            <td class="px-4 py-2 text-sm space-x-1 whitespace-nowrap">
                @if ($canManage)
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
                    action="{{ route('admin.organizations.members.revoke.web', ['organizationId' => $organization->id, 'operatorId' => $member->operatorId]) }}"
                    class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50"
                    @click.self="confirmRevoke = false">
                    @csrf
                    <div class="bg-white rounded-lg shadow-xl p-6 max-w-sm mx-4" @click.stop>
                        <p class="font-semibold text-slate-900 mb-2">Révoquer {{ $member->email }} ?</p>
                        <p class="text-sm text-slate-600 mb-4">
                            Son appartenance à cette organisation prend fin ; le compte console
                            lui-même reste actif. Le dernier Owner ne peut pas être révoqué.
                        </p>
                        <div class="flex justify-end gap-2">
                            <button type="button" @click="confirmRevoke = false"
                                    class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">Annuler</button>
                            <button type="submit"
                                    class="rounded-md bg-rose-600 hover:bg-rose-700 px-3 py-1.5 text-sm font-medium text-white">Confirmer</button>
                        </div>
                    </div>
                </form>
                @endif
            </td>
        </tr>
    @endforeach
    </tbody>
</x-admin.table>
@endsection
