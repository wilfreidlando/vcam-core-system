@extends('layouts.admin')

@section('title', 'Verify MFA')

@section('content')
<div class="w-full max-w-md">

    <div class="flex flex-col items-center mb-8">
        <div class="flex items-center justify-center h-14 w-14 rounded-2xl bg-indigo-600 shadow-lg shadow-indigo-600/20 mb-4">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
        </div>
        <h1 class="text-2xl font-semibold text-slate-900 tracking-tight">Enterprise Core</h1>
        <p class="text-base text-slate-500">Operator console</p>
    </div>

    @if (app()->environment('local'))
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <p class="font-semibold">Local development mode</p>
            <p class="mt-1">
                <code class="font-mono">IDENTITY_DISABLE_TOTP_IN_LOCAL=true</code>
                is set: any 6-digit code is accepted — submit
                <code class="font-mono">123456</code> (or anything else).
            </p>
        </div>
    @endif

    <div class="bg-white shadow-xl shadow-slate-200/60 border border-slate-200 rounded-2xl p-8 sm:p-10">

        <h2 class="text-2xl font-semibold text-slate-900 mb-1">Verify your authenticator</h2>
        <p class="text-base text-slate-500 mb-8">
            Open your authenticator app and enter the current 6-digit code
            for <span class="font-mono text-slate-700">{{ $email }}</span>.
        </p>

        <form method="POST" action="{{ route('admin.mfa.verify') }}" class="space-y-5">
            @csrf
            <input type="hidden" name="challenge_token" value="{{ session('admin.challenge_token') }}" />

            <div>
                <label for="code" class="block text-base font-medium text-slate-700 mb-1.5">Code</label>
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
                    class="block w-full rounded-lg border border-slate-300 px-4 py-3 shadow-sm font-mono tracking-[0.4em] text-center text-2xl" />
                @error('code')
                    <p class="mt-1.5 text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="w-full inline-flex justify-center items-center rounded-lg bg-indigo-600 hover:bg-indigo-700 px-4 py-3 text-base font-semibold text-white shadow-sm transition-colors">
                Verify and sign in
            </button>
        </form>
    </div>
</div>
@endsection
