@extends('layouts.admin')

@section('title', 'Run reconciliation')

@section('content')
<div class="max-w-md mx-auto">
    <div class="bg-white border border-slate-200 rounded-lg p-6">

        <h2 class="text-base font-semibold text-slate-900 mb-1">Trigger reconciliation</h2>
        <p class="text-sm text-slate-600 mb-4">
            Synchronously runs the same code path the scheduled job uses
            (<code class="text-xs bg-slate-100 px-1.5 py-0.5 rounded">admin:reconcile-stuck-payments</code>),
            with a hard cap of 20 rows per call to keep the HTTP response
            responsive.
        </p>

        <form method="POST" action="{{ route('admin.reconciliation.run') }}" class="space-y-4">
            @csrf

            <div>
                <label for="limit" class="block text-sm font-medium text-slate-700 mb-1">
                    Stuck threshold (minutes)
                </label>
                <input
                    type="number"
                    name="limit_override"
                    id="limit"
                    min="1"
                    max="10000"
                    value="15"
                    class="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm" />
                <p class="mt-1 text-xs text-slate-500">
                    Payments older than this many minutes in a non-terminal state will be checked against their provider.
                </p>
            </div>

            <button
                type="submit"
                class="w-full inline-flex justify-center items-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2 text-sm font-medium text-white shadow-sm">
                Run reconciliation now
            </button>
        </form>

        @if (! empty($result))
            <div class="mt-6 border-t border-slate-200 pt-4">
                <p class="text-xs uppercase tracking-wider font-semibold text-slate-500 mb-2">Result</p>
                <dl class="grid grid-cols-3 gap-3 text-sm">
                    <div class="rounded-md bg-slate-50 border border-slate-200 px-3 py-2">
                        <dt class="text-xs text-slate-500">Checked</dt>
                        <dd class="font-mono text-slate-900 tabular-nums">{{ $result['checked'] ?? 0 }}</dd>
                    </div>
                    <div class="rounded-md bg-emerald-50 border border-emerald-200 px-3 py-2">
                        <dt class="text-xs text-emerald-700">Updated</dt>
                        <dd class="font-mono text-emerald-900 tabular-nums">{{ $result['updated'] ?? 0 }}</dd>
                    </div>
                    <div @class([
                        'rounded-md px-3 py-2',
                        'bg-rose-50 border border-rose-200' => ($result['errors'] ?? 0) > 0,
                        'bg-slate-50 border border-slate-200' => ($result['errors'] ?? 0) === 0,
                    ])>
                        <dt @class([
                            'text-xs',
                            'text-rose-700' => ($result['errors'] ?? 0) > 0,
                            'text-slate-500' => ($result['errors'] ?? 0) === 0,
                        ])>Errors</dt>
                        <dd @class([
                            'font-mono tabular-nums',
                            'text-rose-900' => ($result['errors'] ?? 0) > 0,
                            'text-slate-900' => ($result['errors'] ?? 0) === 0,
                        ])>{{ $result['errors'] ?? 0 }}</dd>
                    </div>
                </dl>
            </div>
        @endif
    </div>
</div>
@endsection
