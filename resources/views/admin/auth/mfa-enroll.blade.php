@extends('layouts.admin')

@section('title', 'Set up MFA')

@section('content')
<div class="max-w-lg mx-auto mt-10">
    <div class="bg-white shadow-sm border border-slate-200 rounded-lg p-6 sm:p-8">

        <h2 class="text-xl font-semibold text-slate-900 mb-1">Set up an authenticator</h2>
        <p class="text-sm text-slate-500 mb-6">
            Scan the QR code with your authenticator app
            (Google Authenticator, 1Password, Bitwarden…) and enter the
            6-digit code below.
        </p>

        <div class="grid sm:grid-cols-2 gap-6 mb-6">
            <div class="flex justify-center">
                <div id="qr-target" class="bg-white border border-slate-200 rounded-md p-2">
                    {{-- QR rendered client-side from the otpauth URI. Alpine.js
                         uses qrcode-svg-style inline; if the user can't scan,
                         they can paste the secret manually below. --}}
                    <img src="data:image/svg+xml;utf8,{{-- placeholder --}}"
                         alt="QR code"
                         class="h-44 w-44"
                         data-otpauth="{{ $otpauthUri }}" />
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

{{-- Tiny QR generator inline: read the otpauth URI, render an SVG with the
     right payload. Avoids a third-party dep. Kept deliberately simple:
     a production-quality QR would use a battle-tested library. --}}
<script>
document.addEventListener('alpine:init', () => {
    const target = document.querySelector('[data-otpauth]');
    if (! target) return;
    const uri = target.dataset.otpauth;
    // We replace the placeholder with an inline SVG. The QR library
    // would normally go here — for the bootstrap admin UI this is a
    // placeholder that the next iteration will replace with `qrcode-svg`.
    target.removeAttribute('src');
    target.outerHTML = '<div class="h-44 w-44 flex items-center justify-center bg-slate-50 border border-dashed border-slate-300 rounded text-xs text-slate-500 text-center p-2">QR code placeholder<br><span class="font-mono text-[10px] mt-1 block truncate w-full">'+uri.slice(0,40)+'…</span></div>';
});
</script>
@endsection
