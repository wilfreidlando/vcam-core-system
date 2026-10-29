@extends('layouts.admin')

@section('title', 'Connexion')

@section('content')
<div class="w-full max-w-md">

    {{-- Brand header, above the card — same emblem as the sidebar
         brand, so the public login page and the authenticated console
         read as the same product.

         The emblem is decorative: it carries no information a screen
         reader needs (WCAG 1.1.1), hence `aria-hidden`. It is a shield,
         NOT a stack of horizontal lines — the old mark looked exactly
         like the console's hamburger button (review finding V-03). --}}
    <div class="flex flex-col items-center mb-8">
        <div class="flex items-center justify-center h-14 w-14 rounded-2xl bg-indigo-600 shadow-lg shadow-indigo-600/20 mb-4">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true" focusable="false"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3l7 3v5.25c0 4.28-2.9 8.02-7 9.75-4.1-1.73-7-5.47-7-9.75V6l7-3z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.5 11.5l1.75 1.75 3.5-3.5"/></svg>
        </div>
        <p class="text-2xl font-semibold text-slate-900 tracking-tight">Enterprise Core</p>
        <p class="text-base text-slate-500">Console opérateur</p>
    </div>

    <div class="bg-white shadow-xl shadow-slate-200/60 border border-slate-200 rounded-2xl p-8 sm:p-10">

        {{-- The page title is the <h1> (WCAG 2.4.6 / 1.3.1): the brand
             is a <p>, not a heading — a screen reader navigating by
             headings must land on "Connexion", not on the product name. --}}
        <h1 class="text-2xl font-semibold text-slate-900 mb-1">Connexion</h1>
        <p class="text-base text-slate-500 mb-8">Authentifiez-vous pour accéder à la console opérateur.</p>

        <form method="POST" action="{{ route('admin.login') }}" class="space-y-5">
            @csrf

            {{-- F-09 — the error used to be rendered BETWEEN the two
                 fields, where it read as if it concerned « Email » only,
                 and no screen reader announced it. It now sits at the
                 top of the form, in an assertive live region
                 (WCAG 4.1.3), and is focusable so a keyboard or screen
                 reader user reaches it immediately (WCAG 2.4.3). --}}
            @if ($errors->any())
                <div role="alert" tabindex="-1" id="login-errors" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-base text-rose-800">
                    <p class="font-semibold">Connexion impossible :</p>
                    <ul class="mt-1 list-disc list-inside space-y-0.5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div>
                <label for="email" class="block text-base font-medium text-slate-700 mb-1.5">Email</label>
                <input
                    type="email"
                    name="email"
                    id="email"
                    value="{{ old('email') }}"
                    required
                    @if (! $errors->has('password')) autofocus @endif
                    autocomplete="email"
                    @if ($errors->has('email')) aria-invalid="true" aria-describedby="email-error" @endif
                    class="block w-full rounded-lg border border-slate-300 px-4 py-3 text-base shadow-sm" />
                @error('email')
                    <p id="email-error" class="mt-1.5 text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="block text-base font-medium text-slate-700 mb-1.5">Mot de passe</label>
                <input
                    type="password"
                    name="password"
                    id="password"
                    required
                    @if ($errors->has('password')) autofocus @endif
                    autocomplete="current-password"
                    @if ($errors->has('password')) aria-invalid="true" aria-describedby="password-error" @endif
                    class="block w-full rounded-lg border border-slate-300 px-4 py-3 text-base shadow-sm" />
                @error('password')
                    <p id="password-error" class="mt-1.5 text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <button
                type="submit"
                class="w-full inline-flex justify-center items-center rounded-lg bg-indigo-600 hover:bg-indigo-700 px-4 py-3 text-base font-semibold text-white shadow-sm transition-colors">
                Continuer
            </button>
        </form>
    </div>

    <p class="mt-6 text-sm text-slate-500 text-center">
        Vous avez reçu une invitation ? <a href="{{ route('admin.invitation.show') }}" class="font-medium text-indigo-700 hover:text-indigo-900">Activer mon compte</a>
    </p>

    <p class="mt-3 text-sm text-slate-500 leading-relaxed text-center">
        Lors de leur première connexion, les opérateurs doivent
        enrôler un authentificateur (RFC 6238 TOTP) avant d'obtenir l'accès.
    </p>
</div>
@endsection
