@props([
    'headers' => [],
    'empty' => 'Aucun résultat.',
])

@php
    $headers = collect($headers);
    // Callers pass their <tr>...</tr> rows as the component's slot
    // content, not a `:rows` prop (review finding, Lot 6 follow-up: this
    // component used to iterate an unused `$rows` prop and silently
    // discard every caller's slot content, always rendering the empty
    // state regardless of actual data).
    //
    // The `<tbody>` itself is now the CALLER's responsibility, not
    // this component's — a caller whose rows need per-row Alpine state
    // shared between more than one `<tr>` (an expandable detail row,
    // say) needs its OWN `<tbody x-data="...">` per group, since
    // `x-data` only scopes to descendants and two sibling `<tr>`s
    // inside one shared `<tbody>` can't see each other's state
    // (review finding: `webhook-deliveries/dead.blade.php`'s detail
    // row used to reference a `showDetails` it had no scope to see,
    // so Alpine silently failed to hide it — every row rendered
    // permanently expanded). A caller with one `<tr>` per item still
    // wraps its rows in a single `<tbody>`, same visual result as
    // before.
    $hasRows = trim((string) $slot) !== '';
@endphp

<div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
    <table class="min-w-full divide-y divide-slate-200">
        <thead class="bg-slate-50">
            <tr>
                @foreach ($headers as $label)
                    <th scope="col" class="px-4 py-3 text-left text-sm font-semibold uppercase tracking-wider text-slate-500">
                        {{ $label }}
                    </th>
                @endforeach
            </tr>
        </thead>
        @if ($hasRows)
            {{ $slot }}
        @else
            <tbody class="divide-y divide-slate-100 bg-white">
                <tr>
                    <td colspan="{{ $headers->count() }}" class="px-4 py-8 text-center text-base text-slate-500">
                        {{ $empty }}
                    </td>
                </tr>
            </tbody>
        @endif
    </table>
</div>
