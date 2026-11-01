{{--
    Jauge de quota accessible (WCAG 1.4.1, 4.1.2) — sur le modèle de packages/portal/…/partials/meter.blade.php.

    Props
      label  (requis) nom de la jauge (ex. « Notifications »).
      used   quantité utilisée.
      limit  plafond ; null = illimité (pas de barre, texte seul).
      unit   unité facultative affichée après les nombres (ex. « messages »).
      id     défaut dérivé du label.
    État : < 80 % rien · ≥ 80 % « Presque atteint » · ≥ 100 % « Atteint » — en TEXTE visible, pas par la couleur seule.

    Usage : <x-admin.meter label="Notifications" :used="$c->used" :limit="$c->limit" unit="messages" />
--}}
@props([
    'label',
    'used' => 0,
    'limit' => null,
    'unit' => '',
    'id' => null,
])

@php
    $used = (int) $used;
    $limit = $limit === null ? null : (int) $limit;
    $fmt = fn (int $n): string => number_format($n, 0, ',', ' ');
    $labelId = ($id ?: 'meter-'.\Illuminate\Support\Str::slug((string) $label));
    $pct = $limit === null ? 0 : ($limit > 0 ? (int) min(100, round($used * 100 / $limit)) : 100);
    $state = $limit === null ? 'ok' : ($used >= $limit ? 'danger' : ($pct >= 80 ? 'warn' : 'ok'));
    $unitText = $unit !== '' ? ' '.$unit : '';
    $valueText = $limit === null
        ? $fmt($used).$unitText.' utilisé(s), illimité'
        : $fmt($used).' sur '.$fmt($limit).$unitText;
    $barClass = match ($state) {
        'danger' => '[&::-webkit-progress-value]:bg-rose-600 [&::-moz-progress-bar]:bg-rose-600',
        'warn' => '[&::-webkit-progress-value]:bg-amber-600 [&::-moz-progress-bar]:bg-amber-600',
        default => '[&::-webkit-progress-value]:bg-emerald-600 [&::-moz-progress-bar]:bg-emerald-600',
    };
    $stateText = match ($state) {
        'danger' => 'Atteint',
        'warn' => 'Presque atteint',
        default => null,
    };
    $stateClass = $state === 'danger' ? 'text-rose-700' : 'text-amber-700';
@endphp

<div {{ $attributes->class(['w-full']) }}>
    <div class="flex items-baseline justify-between gap-3 text-sm">
        <span id="{{ $labelId }}" class="font-medium text-slate-700">{{ $label }}</span>
        <span class="text-slate-600">
            @if ($limit === null)
                {{ $fmt($used) }}{{ $unitText }} utilisé(s) · illimité
            @else
                {{ $fmt($used) }} / {{ $fmt($limit) }}{{ $unitText }}
            @endif
        </span>
    </div>
    @if ($limit !== null)
        <progress
            role="progressbar"
            value="{{ $pct }}"
            max="100"
            aria-labelledby="{{ $labelId }}"
            aria-valuemin="0"
            aria-valuemax="100"
            aria-valuenow="{{ $pct }}"
            aria-valuetext="{{ $valueText }}{{ $stateText ? ', '.mb_strtolower($stateText) : '' }}"
            class="mt-1 block h-2 w-full appearance-none overflow-hidden rounded-full border-0 bg-slate-200 [&::-webkit-progress-bar]:bg-slate-200 {{ $barClass }}">{{ $pct }} %</progress>
        @if ($stateText)
            <p class="mt-1 text-sm font-semibold {{ $stateClass }}">{{ $stateText }}</p>
        @endif
    @endif
</div>
