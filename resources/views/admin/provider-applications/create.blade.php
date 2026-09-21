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
        <p class="text-sm text-slate-600 mb-4">
            Registers (or rotates) this Project's own application/merchant
            account with a payment provider — e.g. its MyCoolPay public/
            private key pair, obtained from the provider's own merchant
            dashboard. Saving again with the same provider code overwrites
            the previous key pair; neither key is ever shown again once
            saved.
        </p>

        <form method="POST" action="{{ route('admin.projects.provider-applications.store', ['projectId' => $project->id]) }}" class="space-y-4">
            @csrf

            <div>
                <label for="provider" class="block text-sm font-medium text-slate-700 mb-1">Provider code</label>
                <input
                    type="text"
                    name="provider"
                    id="provider"
                    value="{{ old('provider') }}"
                    required
                    autofocus
                    autocomplete="off"
                    placeholder="my-coolpay"
                    class="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm font-mono" />
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
                class="w-full inline-flex justify-center items-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2 text-sm font-medium text-white shadow-sm">
                Save application
            </button>

            <a href="{{ route('admin.projects.show', ['projectId' => $project->id]) }}" class="block text-center text-sm text-slate-500 hover:text-slate-700">
                Cancel
            </a>
        </form>
    </div>
</div>
@endsection
