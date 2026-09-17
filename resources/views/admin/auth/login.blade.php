@extends('layouts.admin')

@section('title', 'Sign in')

@section('content')
<div class="max-w-md mx-auto mt-12">
    <div class="bg-white shadow-sm border border-slate-200 rounded-lg p-6 sm:p-8">

        <h2 class="text-xl font-semibold text-slate-900 mb-1">Sign in</h2>
        <p class="text-sm text-slate-500 mb-6">Authenticate to access the operator console.</p>

        <form method="POST" action="{{ route('admin.login') }}" class="space-y-4">
            @csrf

            <div>
                <label for="email" class="block text-sm font-medium text-slate-700 mb-1">Email</label>
                <input
                    type="email"
                    name="email"
                    id="email"
                    value="{{ old('email') }}"
                    required
                    autofocus
                    autocomplete="email"
                    class="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm" />
                @error('email')
                    <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-slate-700 mb-1">Password</label>
                <input
                    type="password"
                    name="password"
                    id="password"
                    required
                    autocomplete="current-password"
                    class="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm" />
            </div>

            <button
                type="submit"
                class="w-full inline-flex justify-center items-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2 text-sm font-medium text-white shadow-sm transition-colors">
                Continue
            </button>
        </form>

        <p class="mt-6 text-xs text-slate-500 leading-relaxed">
            First-time operators are required to enrol an authenticator
            (RFC 6238 TOTP) before access is granted.
        </p>
    </div>
</div>
@endsection
