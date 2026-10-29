@extends('layouts.admin')

@section('title', 'Confirmer votre identité')

@section('content')
<div class="w-full max-w-md mx-auto">
    <div class="bg-white shadow-xl shadow-slate-200/60 border border-slate-200 rounded-2xl p-8 sm:p-10">

        <h1 class="text-2xl font-semibold text-slate-900 mb-1">Confirmez votre identité</h1>
        <p class="text-base text-slate-500 mb-8">
            Cette action est sensible. Saisissez le code à 6 chiffres actuel de votre application
            d'authentification : il reste valable 5 minutes pour les actions suivantes.
        </p>

        <form method="POST" action="{{ route('admin.step-up.verify') }}" class="space-y-5">
            @csrf

            @if ($errors->any())
                <div role="alert" tabindex="-1" id="step-up-errors" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-base text-rose-800">
                    <p class="font-semibold">Confirmation impossible :</p>
                    <ul class="mt-1 list-disc list-inside space-y-0.5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div>
                <label for="code" class="block text-base font-medium text-slate-700 mb-1.5">Code de l'application d'authentification</label>
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
                    @if ($errors->has('code')) aria-invalid="true" @endif
                    class="block w-full rounded-lg border border-slate-300 px-4 py-3 shadow-sm font-mono tracking-[0.4em] text-center text-2xl" />
            </div>

            <button type="submit" class="w-full inline-flex justify-center items-center rounded-lg bg-indigo-600 hover:bg-indigo-700 px-4 py-3 text-base font-semibold text-white shadow-sm transition-colors">
                Confirmer
            </button>
        </form>
    </div>
</div>
@endsection
