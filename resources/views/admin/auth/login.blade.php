@extends('layouts.admin')

@section('title', 'Sign in')

@section('content')
<div class="w-full max-w-md">

    {{-- Brand header, above the card — same icon/mark as the sidebar
         brand, so the public login page and the authenticated console
         read as the same product. --}}
    <div class="flex flex-col items-center mb-8">
        <div class="flex items-center justify-center h-14 w-14 rounded-2xl bg-indigo-600 shadow-lg shadow-indigo-600/20 mb-4">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 6h18M3 14h18M3 18h18"/></svg>
        </div>
        <h1 class="text-2xl font-semibold text-slate-900 tracking-tight">Enterprise Core</h1>
        <p class="text-base text-slate-500">Operator console</p>
    </div>

    <div class="bg-white shadow-xl shadow-slate-200/60 border border-slate-200 rounded-2xl p-8 sm:p-10">

        <h2 class="text-2xl font-semibold text-slate-900 mb-1">Sign in</h2>
        <p class="text-base text-slate-500 mb-8">Authenticate to access the operator console.</p>

        <form method="POST" action="{{ route('admin.login') }}" class="space-y-5">
            @csrf

            <div>
                <label for="email" class="block text-base font-medium text-slate-700 mb-1.5">Email</label>
                <input
                    type="email"
                    name="email"
                    id="email"
                    value="{{ old('email') }}"
                    required
                    autofocus
                    autocomplete="email"
                    class="block w-full rounded-lg border border-slate-300 px-4 py-3 text-base shadow-sm" />
                @error('email')
                    <p class="mt-1.5 text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="block text-base font-medium text-slate-700 mb-1.5">Password</label>
                <input
                    type="password"
                    name="password"
                    id="password"
                    required
                    autocomplete="current-password"
                    class="block w-full rounded-lg border border-slate-300 px-4 py-3 text-base shadow-sm" />
            </div>

            <button
                type="submit"
                class="w-full inline-flex justify-center items-center rounded-lg bg-indigo-600 hover:bg-indigo-700 px-4 py-3 text-base font-semibold text-white shadow-sm transition-colors">
                Continue
            </button>
        </form>
    </div>

    <p class="mt-6 text-sm text-slate-500 leading-relaxed text-center">
        First-time operators are required to enrol an authenticator
        (RFC 6238 TOTP) before access is granted.
    </p>
</div>
@endsection
