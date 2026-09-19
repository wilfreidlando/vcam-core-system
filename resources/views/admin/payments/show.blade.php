@extends('layouts.admin')

@section('title', 'Payment')

@section('content')
<div class="space-y-6">
    <div class="bg-white border border-slate-200 rounded-lg p-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
            <div>
                <p class="text-xs uppercase tracking-wider font-semibold text-slate-500">ID</p>
                <p class="font-mono text-slate-700 break-all">{{ $payment->id }}</p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wider font-semibold text-slate-500">Channel</p>
                <p class="font-mono text-slate-700">{{ $payment->channel }}</p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wider text-slate-500 font-semibold">Status</p>
                <p>
                    @php $status = $payment->status; @endphp
                    <span @class([
                        'inline-flex rounded-md px-2 py-0.5 font-mono text-xs',
                        'bg-emerald-100 text-emerald-700' => $status === 'succeeded',
                        'bg-rose-100 text-rose-700' => in_array($status, ['failed', 'expired'], true),
                        'bg-slate-100 text-slate-700' => in_array($status, ['pending', 'processing'], true),
                        'bg-amber-100 text-amber-700' => $status === 'refunded',
                        'bg-purple-100 text-purple-700' => $status === 'canceled',
                    ])>
                        {{ $status }}
                    </span>
                </p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wider text-slate-500 font-semibold">Amount</p>
                <p class="font-mono text-slate-900 tabular-nums">{{ number_format($payment->amount) }} {{ $payment->currency }}</p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wider text-slate-500 font-semibold">Requires action</p>
                <p class="text-slate-700">{{ $payment->requiresAction ? 'Yes' : 'No' }}</p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wider text-slate-500 font-semibold">Action hint</p>
                <p class="text-slate-700">{{ $payment->actionHint ?? '—' }}</p>
            </div>
        </div>
    </div>

    @if (! empty($payment->errorMessage))
        <div class="bg-rose-50 border border-rose-200 rounded-md px-4 py-3 text-sm text-rose-800">
            <span class="font-semibold">Error:</span> {{ $payment->errorMessage }}
        </div>
    @endif

    <div class="text-right">
        <a href="{{ route('admin.payments.index') }}" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">
            ← Back to payments
        </a>
    </div>
</div>
@endsection
