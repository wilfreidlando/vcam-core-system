@props(['status', 'final' => null])
{{-- ADR-0061 — colour by outcome, plus whether the status is final.
     WCAG 1.4.1 : le statut est TOUJOURS écrit en toutes lettres ; la couleur n'est qu'un renfort.
     Un statut inconnu garde un fond neutre (jamais un badge sans contraste défini). --}}
@php
    $known = [
        'emerald' => ['succeeded', 'delivered', 'sent', 'read'],
        'rose' => ['failed', 'expired', 'dead'],
        'amber' => ['refunded'],
        'purple' => ['canceled'],
    ];
    $isKnown = collect($known)->flatten()->contains($status);
@endphp
<span class="inline-flex items-center gap-1.5">
    <span {{ $attributes->class([
        'inline-flex rounded-md px-2 py-0.5 font-mono text-xs',
        'bg-emerald-100 text-emerald-800' => in_array($status, $known['emerald'], true),
        'bg-rose-100 text-rose-800' => in_array($status, $known['rose'], true),
        'bg-amber-100 text-amber-800' => in_array($status, $known['amber'], true),
        'bg-purple-100 text-purple-800' => in_array($status, $known['purple'], true),
        'bg-slate-100 text-slate-700' => ! $isKnown,
    ]) }}>{{ $status }}</span>
    @if ($final !== null)
        <span class="text-xs {{ $final ? 'text-slate-600' : 'text-indigo-700' }}">{{ $final ? 'final' : 'intermédiaire' }}</span>
    @endif
</span>
