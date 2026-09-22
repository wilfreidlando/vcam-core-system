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
    $toneIconBg = match ($tone) {
        'emerald' => 'bg-emerald-100 text-emerald-700',
        'amber'   => 'bg-amber-100 text-amber-700',
        'rose'    => 'bg-rose-100 text-rose-700',
        default   => 'bg-indigo-100 text-indigo-700',
    };
    // Optional `icon` slot (admin dashboard cards) — kept backward
    // compatible: components that never pass it render exactly as
    // before, no icon box.
    $hasIcon = isset($icon) && trim((string) $icon) !== '';
@endphp

<div class="rounded-lg border border-slate-200 bg-white px-4 py-4 shadow-sm ring-1 {{ $toneRing }}">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-sm uppercase tracking-wider font-semibold text-slate-500 truncate">{{ $label }}</p>
            <p class="mt-1 text-3xl font-semibold {{ $toneText }}">{{ number_format((float) $value) }}</p>
            @if ($hint !== '')
                <p class="mt-1 text-sm text-slate-500">{{ $hint }}</p>
            @endif
        </div>
        @if ($hasIcon)
            <div class="shrink-0 rounded-md p-2 {{ $toneIconBg }}">
                {{ $icon }}
            </div>
        @endif
    </div>
</div>
