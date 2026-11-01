@props([
    'label' => '',
    // The series of `MetricsPoint`, oldest first.
    'series' => [],
    // The field of the point the curve draws: mrrMinor, collectedMinor, receivablesMinor.
    'field' => 'mrrMinor',
    'currency' => 'XAF',
    // A compact curve: no frame, no scale — a trend, not a chart.
    'compact' => false,
])

@php
    // The zero-decimal currencies are the ones of the zone; the others are
    // shown in their own unit (ADR-0080 §6 — no conversion, per currency).
    $zero = ['XAF', 'XOF', 'JPY', 'KRW', 'RWF', 'GNF', 'UGX', 'VND'];
    $amount = fn (int $minor): string => in_array($currency, $zero, true)
        ? number_format($minor, 0, ',', ' ').' '.$currency
        : number_format($minor / 100, 2, ',', ' ').' '.$currency;

    $points = collect($series);
    $values = $points->map(fn ($p): int => (int) $p->{$field})->all();
    $n = count($values);
    $max = max(1, ...($values === [] ? [1] : $values));

    // A curve is a polyline in a 320×90 box; the whole series sits between 0 and its maximum.
    $coords = [];
    foreach ($values as $i => $v) {
        $x = $n === 1 ? 160 : round(8 + ($i / ($n - 1)) * 304, 1);
        $y = round(82 - ($v / $max) * 74, 1);
        $coords[] = $x.','.$y;
    }
    $line = implode(' ', $coords);
    $last = $values === [] ? 0 : end($values);
    // A single day is a dot, not a line: mark the latest point.
    $lastPoint = $line === '' ? ['160', '82'] : explode(',', (string) last(explode(' ', $line)));
    $firstDay = $points->first()?->day;
    $lastDay = $points->last()?->day;

    // ADR-0080 §3 — a row computed on another day was **reconstructed**,
    // not observed: the pass replays a range with the figures of the
    // moment it runs. The curve says so, rather than letting a filled
    // day be read as a recorded one.
    $reconstructed = $points->first(
        fn ($p): bool => $p->computedAt !== '' && substr($p->computedAt, 0, 10) !== $p->day,
    );
    $reconstructedAt = $reconstructed === null ? '' : substr($reconstructed->computedAt, 0, 10);
@endphp

@if ($compact)
    <svg viewBox="0 0 320 90" class="h-8 w-24" role="img"
         aria-label="{{ $label }} sur {{ $n }} jour(s), de {{ $amount($values[0] ?? 0) }} à {{ $amount($last) }}">
        <polyline points="{{ $line }}" fill="none" class="stroke-indigo-600" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>
        <circle cx="{{ $lastPoint[0] }}" cy="{{ $lastPoint[1] ?? 82 }}" r="3.5" class="fill-indigo-600"/>
    </svg>
@else
    <figure class="rounded-lg border border-slate-200 bg-white p-4">
        <figcaption class="flex items-baseline justify-between text-sm">
            <span class="font-medium text-slate-800">{{ $label }}</span>
            <span class="tabular-nums text-slate-600">{{ $amount($last) }}</span>
        </figcaption>
        <svg viewBox="0 0 320 90" class="mt-2 h-24 w-full" role="img"
             aria-label="{{ $label }} sur {{ $n }} jour(s), de {{ $amount($values[0] ?? 0) }} à {{ $amount($last) }}">
            <line x1="8" y1="82" x2="312" y2="82" class="stroke-slate-200" stroke-width="1"/>
            <polyline points="{{ $line }}" fill="none" class="stroke-indigo-600" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>
            <circle cx="{{ $lastPoint[0] }}" cy="{{ $lastPoint[1] ?? 82 }}" r="3.5" class="fill-indigo-600"/>
        </svg>
        <div class="mt-1 flex justify-between text-xs text-slate-500">
            <span>{{ $firstDay }}</span><span>max {{ $amount($max) }}</span><span>{{ $lastDay }}</span>
        </div>
        @if ($reconstructedAt !== '')
            <p class="mt-1 text-xs text-amber-700">
                Certains jours ont été reconstruits le {{ $reconstructedAt }} : leurs chiffres sont ceux du jour de la reprise, pas ceux du jour affiché.
            </p>
        @endif
    </figure>
@endif
