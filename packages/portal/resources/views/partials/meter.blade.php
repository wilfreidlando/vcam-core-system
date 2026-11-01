@php
    use Core\Portal\Application\Support\PortalFormat as F;
    $pct = F::percent($e->used, $e->limit);
    $state = $e->limit === null ? 'ok' : ($pct >= 100 ? 'danger' : ($pct >= 80 ? 'warn' : 'ok'));
    $labelId = 'meter-'.\Illuminate\Support\Str::slug((string) $e->key);
    $usedText = number_format((int) $e->used, 0, ',', ' ');
    $limitText = $e->limit === null ? '' : number_format($e->limit, 0, ',', ' ');
@endphp
<div class="meter-row">
    <div class="meter-row__head">
        <span id="{{ $labelId }}">{{ str_replace(['_', '.'], ' ', $e->key) }}</span>
        <span class="muted">
            @if ($e->limit === null)
                {{ $usedText }} {{ (int) $e->used > 1 ? 'utilisés' : 'utilisé' }} · illimité
            @else
                {{ $usedText }} / {{ $limitText }}
            @endif
        </span>
    </div>
    @if ($e->limit !== null)
        <progress class="meter meter--{{ $state }}" value="{{ $pct }}" max="100" aria-labelledby="{{ $labelId }}" aria-valuetext="{{ $usedText }} sur {{ $limitText }}">{{ $pct }} %</progress>
        @if ($state === 'danger')
            <span class="sr-only">Quota atteint</span>
        @elseif ($state === 'warn')
            <span class="sr-only">Quota presque atteint</span>
        @endif
        @if ($e->windowEnd)
            <p class="muted small">Se renouvelle le {{ F::date($e->windowEnd) }}</p>
        @endif
    @endif
</div>
