@props(['status', 'final' => null])
{{-- ADR-0061 — colour by outcome, plus whether the status is final. --}}
<span class="inline-flex items-center gap-1.5">
    <span {{ $attributes->class([
        'inline-flex rounded-md px-2 py-0.5 font-mono text-xs',
        'bg-emerald-100 text-emerald-700' => in_array($status, ['succeeded', 'delivered', 'sent', 'read'], true),
        'bg-rose-100 text-rose-700' => in_array($status, ['failed', 'expired', 'dead'], true),
        'bg-slate-100 text-slate-700' => in_array($status, ['pending', 'processing', 'queued'], true),
        'bg-amber-100 text-amber-700' => $status === 'refunded',
        'bg-purple-100 text-purple-700' => in_array($status, ['canceled'], true),
    ]) }}>{{ $status }}</span>
    @if ($final !== null)
        <span class="text-xs {{ $final ? 'text-slate-500' : 'text-indigo-600' }}">{{ $final ? 'final' : 'intermédiaire' }}</span>
    @endif
</span>
