@props(['env'])
{{-- ADR-0060 — live in red (real money), sandbox in amber. --}}
<span {{ $attributes->class([
    'inline-flex rounded-md px-2 py-0.5 text-xs font-semibold',
    'bg-rose-100 text-rose-700' => $env === 'live',
    'bg-amber-100 text-amber-800' => $env !== 'live',
]) }}>{{ strtoupper((string) $env) }}</span>
