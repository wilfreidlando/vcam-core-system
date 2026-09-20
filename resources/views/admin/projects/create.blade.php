@extends('layouts.admin')

@section('title', 'New project')

@section('content')
<div class="max-w-md mx-auto">
    <div class="bg-white border border-slate-200 rounded-lg p-6">

        <h2 class="text-base font-semibold text-slate-900 mb-1">New project</h2>
        <p class="text-sm text-slate-600 mb-4">
            A project belongs to exactly one organization and is the unit
            everything else (API keys, payments, wallets) is scoped to.
        </p>

        <form method="POST" action="{{ route('admin.projects.store') }}" class="space-y-4">
            @csrf

            <div>
                <label for="organization_id" class="block text-sm font-medium text-slate-700 mb-1">Organization</label>
                <select
                    name="organization_id"
                    id="organization_id"
                    required
                    class="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm">
                    <option value="" disabled @selected(old('organization_id', $selectedOrganizationId) === '')>Select an organization…</option>
                    @foreach ($organizations as $org)
                        <option value="{{ $org->id }}" @selected(old('organization_id', $selectedOrganizationId) === $org->id)>
                            {{ $org->name }}
                        </option>
                    @endforeach
                </select>
                @error('organization_id')
                    <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                @enderror
                @if (count($organizations) === 0)
                    <p class="mt-1 text-xs text-amber-600">
                        No organizations exist yet — <a href="{{ route('admin.organizations.create') }}" class="underline">create one first</a>.
                    </p>
                @endif
            </div>

            <div>
                <label for="name" class="block text-sm font-medium text-slate-700 mb-1">Name</label>
                <input
                    type="text"
                    name="name"
                    id="name"
                    value="{{ old('name') }}"
                    required
                    class="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm" />
                @error('name')
                    <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <button
                type="submit"
                class="w-full inline-flex justify-center items-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2 text-sm font-medium text-white shadow-sm">
                Create project
            </button>
        </form>
    </div>
</div>
@endsection
