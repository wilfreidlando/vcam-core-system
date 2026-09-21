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
    $hasRows = trim((string) $slot) !== '';
@endphp

<div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
    <table class="min-w-full divide-y divide-slate-200">
        <thead class="bg-slate-50">
            <tr>
                @foreach ($headers as $label)
                    <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                        {{ $label }}
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 bg-white">
            @if ($hasRows)
                {{ $slot }}
            @else
                <tr>
                    <td colspan="{{ $headers->count() }}" class="px-4 py-6 text-center text-sm text-slate-500">
                        {{ $empty }}
                    </td>
                </tr>
            @endif
        </tbody>
    </table>
</div>
