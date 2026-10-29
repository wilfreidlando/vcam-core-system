@extends('layouts.admin')

@section('title', 'Activer mon compte')

@section('content')
<div class="w-full max-w-md">

    <div class="flex flex-col items-center mb-8">
        <div class="flex items-center justify-center h-14 w-14 rounded-2xl bg-indigo-600 shadow-lg shadow-indigo-600/20 mb-4">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 6h18M3 14h18M3 18h18"/></svg>
        </div>
        <h1 class="text-2xl font-semibold text-slate-900 tracking-tight">Enterprise Core</h1>
        <p class="text-base text-slate-500">Console opérateur</p>
    </div>

    <div class="bg-white shadow-xl shadow-slate-200/60 border border-slate-200 rounded-2xl p-8 sm:p-10">
        <h2 class="text-2xl font-semibold text-slate-900 mb-1">Activer mon compte</h2>
        <p class="text-base text-slate-500 mb-8">
            Collez le jeton d'activation qu'on vous a remis et choisissez votre mot de passe. Vous
            configurerez ensuite votre authentificateur.
        </p>

        @if (session('status'))
            <div class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">{{ session('status') }}</div>
        @endif

        <form method="POST" action="{{ route('admin.invitation.accept') }}" class="space-y-5">
            @csrf

            <div>
                <label for="token" class="block text-base font-medium text-slate-700 mb-1.5">Jeton d'activation</label>
                <input type="text" name="token" id="token" required autofocus autocomplete="off" spellcheck="false"
                       class="block w-full rounded-lg border border-slate-300 px-4 py-3 text-base font-mono shadow-sm" />
                @error('token')
                    <p class="mt-1.5 text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="name" class="block text-base font-medium text-slate-700 mb-1.5">Votre nom <span class="text-slate-400 font-normal">(facultatif)</span></label>
                <input type="text" name="name" id="name" value="{{ old('name') }}" autocomplete="name"
                       class="block w-full rounded-lg border border-slate-300 px-4 py-3 text-base shadow-sm" />
            </div>

            <div>
                <label for="password" class="block text-base font-medium text-slate-700 mb-1.5">Mot de passe</label>
                <input type="password" name="password" id="password" required autocomplete="new-password"
                       class="block w-full rounded-lg border border-slate-300 px-4 py-3 text-base shadow-sm" />
                @error('password')
                    <p class="mt-1.5 text-sm text-rose-600">{{ $message }}</p>
                @enderror
                <p class="mt-1.5 text-sm text-slate-500">Si vous avez déjà un compte, saisissez son mot de passe actuel : il n'est pas remplacé.</p>
            </div>

            <div>
                <label for="password_confirmation" class="block text-base font-medium text-slate-700 mb-1.5">Confirmez le mot de passe</label>
                <input type="password" name="password_confirmation" id="password_confirmation" required autocomplete="new-password"
                       class="block w-full rounded-lg border border-slate-300 px-4 py-3 text-base shadow-sm" />
            </div>

            <button type="submit"
                    class="w-full inline-flex justify-center items-center rounded-lg bg-indigo-600 hover:bg-indigo-700 px-4 py-3 text-base font-semibold text-white shadow-sm transition-colors">
                Activer mon compte
            </button>
        </form>
    </div>

    <p class="mt-6 text-sm text-slate-500 text-center">
        Vous avez déjà activé votre compte ? <a href="{{ route('admin.login.show') }}" class="font-medium text-indigo-700 hover:text-indigo-900">Se connecter</a>
    </p>
</div>
@endsection
