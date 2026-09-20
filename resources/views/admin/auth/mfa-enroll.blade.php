@extends('layouts.admin')

@section('title', 'Set up MFA')

@section('content')
<div class="max-w-lg mx-auto mt-10">
    @if (app()->environment('local'))
        <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <p class="font-semibold">Local development mode</p>
            <p class="mt-1">
                <code class="font-mono">IDENTITY_DISABLE_TOTP_IN_LOCAL=true</code> is set:
                any 6-digit code is accepted — no need to scan the QR.
                Submit <code class="font-mono">123456</code> (or anything else)
                to enroll.
            </p>
        </div>
    @endif

    <div class="bg-white shadow-sm border border-slate-200 rounded-lg p-6 sm:p-8">

        <h2 class="text-xl font-semibold text-slate-900 mb-1">Set up an authenticator</h2>
        <p class="text-sm text-slate-500 mb-6">
            Scan the QR code with your authenticator app
            (Google Authenticator, 1Password, Bitwarden…) and enter the
            6-digit code below.
        </p>

        <div class="grid sm:grid-cols-2 gap-6 mb-6">
            <div class="flex justify-center">
                <div class="bg-white border border-slate-200 rounded-md p-3 inline-block">
                    {{-- Server-rendered QR (chillerlan/php-qrcode) — scans
                         directly into Google Authenticator / 1Password /
                         Bitwarden. No JS, no CDN, no flash of placeholder. --}}
                    {!! $qrSvg !!}
                </div>
            </div>

            <div class="space-y-3">
                <div>
                    <p class="text-xs uppercase tracking-wider text-slate-500 font-semibold">Account</p>
                    <p class="text-sm font-mono text-slate-900 truncate">{{ $email }}</p>
                </div>
                <div>
                    <p class="text-xs uppercase tracking-wider text-slate-500 font-semibold">Secret (manual entry)</p>
                    <code class="block text-sm font-mono bg-slate-50 border border-slate-200 rounded px-2 py-1.5 break-all select-all">{{ $secret }}</code>
                </div>
                <p class="text-xs text-slate-500 leading-relaxed">
                    Can't scan? Paste the secret above into your authenticator's
                    "manual entry" flow. The QR and the secret encode the
                    same TOTP parameters.
                </p>
            </div>
        </div>

        <form method="POST" action="{{ route('admin.mfa.confirm') }}" class="space-y-4">
            @csrf
            <input type="hidden" name="challenge_token" value="{{ $confirmChallengeToken }}" />

            <div>
                <label for="code" class="block text-sm font-medium text-slate-700 mb-1">
                    6-digit code
                </label>
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
                Confirm and sign in
            </button>
        </form>
    </div>
</div>
@endsection
