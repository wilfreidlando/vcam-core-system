@props([
    'label' => '',
    'value' => 0,
    'hint' => '',
    'tone' => 'slate', // 'slate' | 'emerald' | 'amber' | 'rose'
])

@php
    $toneRing = match ($tone) {
        'emerald' => 'ring-emerald-200',
        'amber'   => 'ring-amber-200',
        'rose'    => 'ring-rose-200',
        default   => 'ring-slate-200',
    };
    $toneText = match ($tone) {
        'emerald' => 'text-emerald-700',
        'amber'   => 'text-amber-700',
        'rose'    => 'text-rose-700',
        default   => 'text-slate-900',
    };
@endphp

<div class="rounded-lg border border-slate-200 bg-white px-4 py-4 shadow-sm ring-1 {{ $toneRing }}">
    <p class="text-xs uppercase tracking-wider font-semibold text-slate-500">{{ $label }}</p>
    <p class="mt-1 text-3xl font-semibold {{ $toneText }}">{{ number_format((float) $value) }}</p>
    @if ($hint !== '')
        <p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>
    @endif
</div>
