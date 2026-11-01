<div class="brand brand--guest">
    @if ($branding->logoUrl)
        <img class="brand__logo" src="{{ $branding->logoUrl }}" alt="">
    @else
        <span class="brand__mark" aria-hidden="true">{{ mb_strtoupper(mb_substr($branding->displayName, 0, 1)) }}</span>
    @endif
    <span class="brand__name">{{ $branding->displayName }}</span>
</div>
