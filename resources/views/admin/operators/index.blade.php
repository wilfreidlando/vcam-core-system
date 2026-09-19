@extends('layouts.admin')

@section('title', 'Operators')

@section('content')
@if (! empty($created))
    <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3">
        <p class="text-sm font-semibold text-emerald-800">Operator created: {{ $created['email'] }}</p>
        <p class="mt-1 text-sm text-emerald-700">
            One-time password — shown only now, never stored or logged:
            <code class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 font-mono text-emerald-900">{{ $created['plaintext_password'] }}</code>
        </p>
    </div>
@endif

<div class="mb-4 flex justify-end">
    <a href="{{ route('admin.operators.create') }}"
       class="inline-flex items-center gap-2 rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2 text-sm font-medium text-white shadow-sm">
        New operator
    </a>
</div>

<x-admin.table
    :headers="['Email', 'Name', 'Role', 'MFA', 'Status', 'Created', 'Action']"
    :empty="'No operators yet.'">
    @foreach ($operators as $op)
        <tr x-data="{ confirmRevoke: false }">
            <td class="px-4 py-2 text-sm text-slate-900">{{ $op->email }}</td>
            <td class="px-4 py-2 text-sm text-slate-700">{{ $op->name }}</td>
            <td class="px-4 py-2 text-xs">
                <span class="inline-flex rounded-md bg-slate-100 px-2 py-0.5 font-mono text-slate-700">
                    {{ $op->role }}
                </span>
            </td>
            <td class="px-4 py-2 text-xs">
                <span @class([
                    'inline-flex rounded-full px-2 py-0.5 font-mono',
                    'bg-emerald-100 text-emerald-700' => $op->mfaEnabled,
                    'bg-slate-100 text-slate-700' => ! $op->mfaEnabled,
                ])>
                    {{ $op->mfaEnabled ? 'Enrolled' : 'Not enrolled' }}
                </span>
            </td>
            <td class="px-4 py-2 text-xs">
                <span @class([
                    'inline-flex rounded-full px-2 py-0.5 font-mono',
                    'bg-rose-100 text-rose-700' => $op->revoked,
                    'bg-emerald-100 text-emerald-700' => ! $op->revoked,
                ])>
                    {{ $op->revoked ? 'Revoked' : 'Active' }}
                </span>
            </td>
            <td class="px-4 py-2 text-xs font-mono text-slate-600 whitespace-nowrap">
                {{ $op->createdAt->format('Y-m-d H:i:s') }}
            </td>
            <td class="px-4 py-2 text-xs">
                @unless ($op->revoked)
                    <button
                        @click="confirmRevoke = true"
                        type="button"
                        class="inline-flex items-center gap-1 rounded-md bg-rose-50 px-2 py-1 font-medium text-rose-700 hover:bg-rose-100">
                        Revoke
                    </button>
                    <form
                        x-show="confirmRevoke"
                        x-cloak
                        @submit="confirmRevoke = false"
                        method="POST"
                        action="{{ route('admin.operators.revoke', ['operatorId' => $op->id]) }}"
                        class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50"
                        @click.self="confirmRevoke = false">
                        @csrf
                        <div class="bg-white rounded-lg shadow-xl p-6 max-w-sm mx-4" @click.stop>
                            <p class="font-semibold text-slate-900 mb-2">Revoke {{ $op->email }}?</p>
                            <p class="text-sm text-slate-600 mb-4">
                                Their bearer tokens are rejected on their
                                very next request. This cannot be undone
                                from here.
                            </p>
                            <div class="flex justify-end gap-2">
                                <button type="button" @click="confirmRevoke = false"
                                        class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">Cancel</button>
                                <button type="submit"
                                        class="rounded-md bg-rose-600 hover:bg-rose-700 px-3 py-1.5 text-sm font-medium text-white">Confirm</button>
                            </div>
                        </div>
                    </form>
                @endunless
            </td>
        </tr>
    @endforeach
</x-admin.table>

<x-admin.pagination :page="$page" :per-page="$perPage" :total="$total" />
@endsection
