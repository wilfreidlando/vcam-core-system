@extends('layouts.admin')

@section('title', 'New organization')

@section('content')
<div class="max-w-md mx-auto">
    <div class="bg-white border border-slate-200 rounded-lg p-6">

        <h2 class="text-base font-semibold text-slate-900 mb-1">New organization</h2>
        <p class="text-sm text-slate-600 mb-4">
            Organizations group projects under one tenant. Projects are
            created separately, once the organization exists.
        </p>

        <form method="POST" action="{{ route('admin.organizations.store') }}" class="space-y-4">
            @csrf

            <div>
                <label for="name" class="block text-sm font-medium text-slate-700 mb-1">Name</label>
                <input
                    type="text"
                    name="name"
                    id="name"
                    value="{{ old('name') }}"
                    required
                    autofocus
                    class="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm" />
                @error('name')
                    <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <button
                type="submit"
                class="w-full inline-flex justify-center items-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2 text-sm font-medium text-white shadow-sm">
                Create organization
            </button>
        </form>
    </div>
</div>
@endsection
