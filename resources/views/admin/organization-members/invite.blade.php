@extends('layouts.admin')

@section('title', 'Inviter un membre — '.$organization->name)

@section('content')
<div class="max-w-md mx-auto">
    <div class="bg-white border border-slate-200 rounded-lg p-6">

        <h2 class="text-lg font-semibold text-slate-900 mb-1">
            Inviter un membre dans {{ $organization->name }}
        </h2>
        <p class="text-base text-slate-600 mb-4">
            L'invité reçoit un jeton d'activation à usage unique, valable 72 h. Il choisit lui-même
            son mot de passe et enrôle son MFA à l'activation — aucun mot de passe n'est choisi par
            le système ni transmis (ADR-0069 §4).
        </p>

        <form method="POST" action="{{ route('admin.organizations.members.store', ['organizationId' => $organization->id]) }}" class="space-y-4">
            @csrf

            <div>
                <label for="email" class="block text-base font-medium text-slate-700 mb-1">Email</label>
                <input
                    type="email"
                    name="email"
                    id="email"
                    value="{{ old('email') }}"
                    required
                    autofocus
                    class="block w-full rounded-md border border-slate-300 px-4 py-2.5 text-base shadow-sm" />
                @error('email')
                    <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="role" class="block text-base font-medium text-slate-700 mb-1">Rôle</label>
                <select name="role" id="role" required
                        class="block w-full rounded-md border border-slate-300 px-4 py-2.5 text-base shadow-sm">
                    @foreach ($roles as $role)
                        <option value="{{ $role }}" @selected(old('role') === $role)>{{ $role }}</option>
                    @endforeach
                </select>
                @error('role')
                    <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                @enderror
                <p class="mt-1 text-sm text-slate-500">
                    Seul un <code class="font-mono">org_owner</code> peut attribuer <code class="font-mono">org_owner</code> ;
                    aucun rôle de plateforme ne s'attribue ici.
                </p>
            </div>

            <button
                type="submit"
                class="w-full inline-flex justify-center items-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2.5 text-base font-medium text-white shadow-sm">
                Envoyer l'invitation
            </button>
        </form>
    </div>
</div>
@endsection
