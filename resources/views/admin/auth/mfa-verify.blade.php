@extends('layouts.admin')

@section('title', 'Verify MFA')

@section('content')
<div class="max-w-md mx-auto mt-12">
    @if (app()->environment('local'))
        <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <p class="font-semibold">Local development mode</p>
            <p class="mt-1">
                <code class="font-mono">IDENTITY_DISABLE_TOTP_IN_LOCAL=true</code>
                is set: any 6-digit code is accepted — submit
                <code class="font-mono">123456</code> (or anything else).
            </p>
        </div>
    @endif

    <div class="bg-white shadow-sm border border-slate-200 rounded-lg p-6 sm:p-8">

        <h2 class="text-xl font-semibold text-slate-900 mb-1">Verify your authenticator</h2>
        <p class="text-sm text-slate-500 mb-6">
            Open your authenticator app and enter the current 6-digit code
            for <span class="font-mono text-slate-700">{{ $email }}</span>.
        </p>

        <form method="POST" action="{{ route('admin.mfa.verify') }}" class="space-y-4">
            @csrf
            <input type="hidden" name="challenge_token" value="{{ session('admin.challenge_token') }}" />

            <div>
                <label for="code" class="block text-sm font-medium text-slate-700 mb-1">Code</label>
                <input
                    type="text"
                    inputmode="numeric"
                    pattern="[0-9]*"
                    minlength="6"
                    maxlength="8"
                    name="code"
                    id="code"
                    required
                    autofocus
                    autocomplete="one-time-code"
                    class="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm font-mono tracking-widest text-center text-lg" />
                @error('code')
                    <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="w-full inline-flex justify-center items-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2 text-sm font-medium text-white shadow-sm">
                Verify and sign in
            </button>
        </form>
    </div>
</div>
@endsection
