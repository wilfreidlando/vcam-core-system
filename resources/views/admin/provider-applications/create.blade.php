@extends('layouts.admin')

@section('title', 'Provider applications')
@section('subtitle', $project->name)

@section('content')
<div class="max-w-md mx-auto space-y-6">

    {{-- Existing applications — never shows the private key (there is no
         read path for it; ProviderApplicationRepository::save() is
         upsert-only), just confirmation of what's registered. --}}
    <div class="bg-white border border-slate-200 rounded-lg p-6">
        <h2 class="text-base font-semibold text-slate-900 mb-3">Registered applications</h2>
        @if (count($applications) === 0)
            <p class="text-sm text-slate-500">No provider application registered for this project yet.</p>
        @else
            <ul class="divide-y divide-slate-100">
                @foreach ($applications as $application)
                    <li class="py-2 flex items-center justify-between text-sm">
                        <span class="font-mono font-medium text-slate-900">{{ $application->providerCode }}</span>
                        <span class="font-mono text-xs text-slate-500 truncate max-w-[12rem]" title="{{ $application->publicKey }}">
                            {{ $application->publicKey }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="bg-white border border-slate-200 rounded-lg p-6">

        <h2 class="text-base font-semibold text-slate-900 mb-1">Register provider application</h2>
        <p class="text-sm text-slate-600 mb-3">
            An "application" is this Project's own merchant credentials
            with a payment provider — e.g. a MyCoolPay public/private key
            pair, obtained from that provider's own merchant dashboard.
        </p>

        {{-- The two conceptual points that used to be missing from this
             form entirely (review finding): the provider list is real,
             and a project has AT MOST ONE application per provider. --}}
        <div class="mb-5 rounded-md border border-indigo-100 bg-indigo-50 px-3 py-2.5 text-xs text-indigo-900 leading-relaxed">
            <p>
                <span class="font-semibold">Provider</span> below lists only
                providers this Core actually has an adapter for — not a
                free-text code you have to get right by hand.
            </p>
            <p class="mt-1.5">
                <span class="font-semibold">One application per provider.</span>
                A single provider account (e.g. MyCoolPay) can itself hold
                several distinct applications on its own dashboard, each
                with its own keys — but this Project pins exactly <em>one</em>
                of them per provider at a time. Saving again for a provider
                that already has one <span class="font-semibold">rotates</span>
                it (overwrites the key pair); it does not register a second
                one alongside it.
            </p>
        </div>

        <form method="POST" action="{{ route('admin.projects.provider-applications.store', ['projectId' => $project->id]) }}" class="space-y-4">
            @csrf

            <div>
                <label for="provider" class="block text-sm font-medium text-slate-700 mb-1">Provider</label>
                @if (count($availableProviders) === 0)
                    <p class="text-sm text-rose-600">
                        No payment provider adapter is registered in this Core yet — there is nothing to select.
                    </p>
                @else
                    <select
                        name="provider"
                        id="provider"
                        required
                        autofocus
                        class="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm font-mono bg-white">
                        @foreach ($availableProviders as $code)
                            <option value="{{ $code }}" @selected(old('provider') === $code)>
                                {{ $code }}@if (in_array($code, $registeredProviderCodes, true)) — already registered, saving will rotate it @endif
                            </option>
                        @endforeach
                    </select>
                @endif
                @error('provider')
                    <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="public_key" class="block text-sm font-medium text-slate-700 mb-1">Public key</label>
                <input
                    type="text"
                    name="public_key"
                    id="public_key"
                    value="{{ old('public_key') }}"
                    required
                    autocomplete="off"
                    class="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm font-mono" />
                @error('public_key')
                    <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="private_key" class="block text-sm font-medium text-slate-700 mb-1">Private key</label>
                <input
                    type="password"
                    name="private_key"
                    id="private_key"
                    required
                    autocomplete="off"
                    class="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm font-mono" />
                <p class="mt-1 text-xs text-slate-500">Encrypted at rest, never displayed again after saving.</p>
                @error('private_key')
                    <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <button
                type="submit"
                @disabled(count($availableProviders) === 0)
                @class([
                    'w-full inline-flex justify-center items-center rounded-md px-4 py-2 text-sm font-medium shadow-sm',
                    'bg-indigo-600 hover:bg-indigo-700 text-white' => count($availableProviders) > 0,
                    'bg-slate-200 text-slate-500 cursor-not-allowed' => count($availableProviders) === 0,
                ])>
                Save application
            </button>

            <a href="{{ route('admin.projects.show', ['projectId' => $project->id]) }}" class="block text-center text-sm text-slate-500 hover:text-slate-700">
                Cancel
            </a>
        </form>
    </div>
</div>
@endsection
