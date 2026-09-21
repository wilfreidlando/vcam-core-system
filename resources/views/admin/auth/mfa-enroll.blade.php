@extends('layouts.admin')

@section('title', 'Configuration MFA')

@section('content')
<div class="w-full max-w-lg">

    <div class="flex flex-col items-center mb-8">
        <div class="flex items-center justify-center h-14 w-14 rounded-2xl bg-indigo-600 shadow-lg shadow-indigo-600/20 mb-4">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
        </div>
        <h1 class="text-2xl font-semibold text-slate-900 tracking-tight">Enterprise Core</h1>
        <p class="text-base text-slate-500">Console opérateur</p>
    </div>

    @if (app()->environment('local'))
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <p class="font-semibold">Mode développement local</p>
            <p class="mt-1">
                <code class="font-mono">IDENTITY_DISABLE_TOTP_IN_LOCAL=true</code> est actif :
                n'importe quel code à 6 chiffres est accepté — pas besoin de scanner le QR.
                Saisissez <code class="font-mono">123456</code> (ou autre chose)
                pour l'enrôlement.
            </p>
        </div>
    @endif

    <div class="bg-white shadow-xl shadow-slate-200/60 border border-slate-200 rounded-2xl p-8 sm:p-10">

        <h2 class="text-2xl font-semibold text-slate-900 mb-1">Configurer un authentificateur</h2>
        <p class="text-base text-slate-500 mb-8">
            Scannez le QR code avec votre application d'authentification
            (Google Authenticator, 1Password, Bitwarden…) puis saisissez le
            code à 6 chiffres ci-dessous.
        </p>

        <div class="grid sm:grid-cols-2 gap-6 mb-8">
            <div class="flex justify-center">
                <div class="bg-white border border-slate-200 rounded-lg p-3 inline-block">
                    {{-- Server-rendered QR (chillerlan/php-qrcode) — scans
                         directly into Google Authenticator / 1Password /
                         Bitwarden. No JS, no CDN, no flash of placeholder. --}}
                    {!! $qrSvg !!}
                </div>
            </div>

            <div class="space-y-4">
                <div>
                    <p class="text-sm uppercase tracking-wider text-slate-500 font-semibold">Compte</p>
                    <p class="text-base font-mono text-slate-900 truncate">{{ $email }}</p>
                </div>
                <div>
                    <p class="text-sm uppercase tracking-wider text-slate-500 font-semibold">Secret (saisie manuelle)</p>
                    <code class="block text-sm font-mono bg-slate-50 border border-slate-200 rounded px-3 py-2 break-all select-all">{{ $secret }}</code>
                </div>
                <p class="text-sm text-slate-500 leading-relaxed">
                    Impossible de scanner ? Collez le secret ci-dessus dans la
                    saisie manuelle de votre authentificateur. Le QR et le
                    secret encodent les mêmes paramètres TOTP.
                </p>
            </div>
        </div>

        <form method="POST" action="{{ route('admin.mfa.confirm') }}" class="space-y-5">
            @csrf
            <input type="hidden" name="challenge_token" value="{{ $confirmChallengeToken }}" />

            <div>
                <label for="code" class="block text-base font-medium text-slate-700 mb-1.5">
                    Code à 6 chiffres
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
                    class="block w-full rounded-lg border border-slate-300 px-4 py-3 shadow-sm font-mono tracking-[0.4em] text-center text-2xl" />
                @error('code')
                    <p class="mt-1.5 text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="w-full inline-flex justify-center items-center rounded-lg bg-indigo-600 hover:bg-indigo-700 px-4 py-3 text-base font-semibold text-white shadow-sm transition-colors">
                Confirmer et se connecter
            </button>
        </form>
    </div>
</div>
@endsection
